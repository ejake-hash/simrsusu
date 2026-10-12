<?php

namespace App\Modules\Integrasi\Application;

use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\KlienVclaim;
use App\Modules\Integrasi\Infrastructure\Penyamar;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Baca VClaim untuk modul lain (tiket 1.9 butir 3; FR-50-01 baca saja; catatan lapangan §3, §4.1, §10). Baca sinkron
 * (dipakai layar pendaftaran fase 2) tetap lewat circuit breaker: sirkuit terbuka → gagal cepat tanpa memanggil BPJS.
 * Parameter path divalidasi ketat sebelum masuk URL. Endpoint tulis (SEP, rujukan keluar, kontrol, SPRI) di fase 2.
 */
final class LayananVclaim
{
    /** Referensi yang boleh dibaca: jenis => [template path, TTL detik] (catatan §10). */
    public const REFERENSI = [
        'diagnosa' => ['referensi/diagnosa/{q}', 1800],
        'poli' => ['referensi/poli/{q}', 1800],
        'faskes' => ['referensi/faskes/{q}/{jns}', 1800],
        'dokter' => ['referensi/dokter/{q}', 1800],
        'propinsi' => ['referensi/propinsi', 86400],
        'kabupaten' => ['referensi/kabupaten/propinsi/{kd}', 86400],
        'kecamatan' => ['referensi/kecamatan/kabupaten/{kd}', 86400],
        'kelasrawat' => ['referensi/kelasrawat', 86400],
        'spesialistik' => ['referensi/spesialistik', 86400],
        'ruangrawat' => ['referensi/ruangrawat', 86400],
        'carakeluar' => ['referensi/carakeluar', 86400],
        'pascapulang' => ['referensi/pascapulang', 86400],
    ];

    public function __construct(
        private readonly KelolaKredensial $kredensial,
        private readonly KlienVclaim $klien,
        private readonly SirkuitIntegrasi $sirkuit,
    ) {}

    /**
     * GET /Peserta/{nik|nokartu}/{nomor}/tglSEP/{tanggal}. `statusPeserta.kode` ≠ "0" = tidak aktif (dibaca pemanggil).
     *
     * @return array{berhasil: bool, data: array<string, mixed>|null, pesan: string|null}
     */
    public function peserta(string $jenis, string $nomor, string $tanggal): array
    {
        if (! in_array($jenis, ['nik', 'nokartu'], true) || preg_match('/^\d{13,16}$/', $nomor) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) !== 1) {
            throw new InvalidArgumentException('Jenis, nomor, atau tanggal peserta tidak valid.');
        }
        $hasil = $this->baca("Peserta/$jenis/$nomor/tglSEP/$tanggal", "Peserta/$jenis/***/tglSEP/***");

        return [...$hasil, 'data' => is_array($hasil['data']['peserta'] ?? null) ? $hasil['data']['peserta'] : null];
    }

    /**
     * Rujukan dicari di FKTP dulu, lalu RS (catatan §4.1: rujukan antar-RS tidak ditemukan di /Rujukan/{no}). `asal`
     * menentukan asalRujukan SEP: "1" FKTP, "2" FKRTL. Respons yang membungkus rujukan dalam array diambil elemen pertama.
     *
     * @return array{berhasil: bool, data: array<string, mixed>|null, pesan: string|null, asal: string|null}
     */
    public function rujukan(string $nomor): array
    {
        if (preg_match('/^[A-Za-z0-9]{1,30}$/', $nomor) !== 1) {
            throw new InvalidArgumentException('Nomor rujukan tidak valid.');
        }
        foreach (['1' => ["Rujukan/$nomor", 'Rujukan/***'], '2' => ["Rujukan/RS/$nomor", 'Rujukan/RS/***']] as $asal => [$path, $pathLog]) {
            $hasil = $this->baca($path, $pathLog);
            $rujukan = $hasil['data']['rujukan'] ?? null;
            if (is_array($rujukan) && array_is_list($rujukan)) {
                $rujukan = $rujukan[0] ?? null;
            }
            if ($hasil['berhasil'] && is_array($rujukan)) {
                return ['berhasil' => true, 'data' => $rujukan, 'pesan' => null, 'asal' => (string) $asal];
            }
            if ($hasil['gangguan']) {
                return ['berhasil' => false, 'data' => null, 'pesan' => $hasil['pesan'], 'asal' => null];
            }
        }

        return ['berhasil' => false, 'data' => null, 'pesan' => 'Rujukan tidak ditemukan di FKTP maupun RS.', 'asal' => null];
    }

    /**
     * @param  array<string, string>  $param  isi placeholder ({q}, {jns}, {kd}); huruf, angka, spasi, titik, minus
     * @return array{berhasil: bool, data: mixed, pesan: string|null}
     */
    public function referensi(string $jenis, array $param = []): array
    {
        [$template, $ttl] = self::REFERENSI[$jenis] ?? throw new InvalidArgumentException('Referensi VClaim tidak dikenal.');
        $path = (string) preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($param) {
            $nilai = (string) ($param[$m[1]] ?? '');
            if (preg_match('/^[\pL\pN .\-]{1,50}$/u', $nilai) !== 1 || str_contains($nilai, '..') || trim($nilai, '. ') === '') {
                throw new InvalidArgumentException('Parameter referensi tidak valid.');
            }

            return rawurlencode($nilai);
        }, $template);
        $lingkungan = $this->kredensial->lingkunganAktif(SistemIntegrasi::BpjsVclaim);
        $kunci = 'integrasi:vclaim:ref:'.$lingkungan.':'.sha1($path);
        $simpan = Cache::get($kunci);
        if (is_array($simpan)) {
            return ['berhasil' => true, 'data' => $simpan, 'pesan' => null];
        }
        $hasil = $this->baca($path);
        if ($hasil['berhasil'] && is_array($hasil['data'])) {
            Cache::put($kunci, $hasil['data'], $ttl);
        }

        return ['berhasil' => $hasil['berhasil'], 'data' => $hasil['data'], 'pesan' => $hasil['pesan']];
    }

    /** @return array{berhasil: bool, gangguan: bool, data: mixed, pesan: string|null} */
    private function baca(string $path, ?string $pathLog = null): array
    {
        $sistem = SistemIntegrasi::BpjsVclaim;
        $lingkungan = $this->kredensial->lingkunganAktif($sistem);
        if ($lingkungan === null) {
            return ['berhasil' => false, 'gangguan' => false, 'data' => null, 'pesan' => 'Integrasi BPJS VClaim belum diaktifkan.'];
        }
        // Kredensial dibuka sebelum mengambil jatah circuit breaker: kegagalan di sini tidak menahan jatah setengah terbuka.
        try {
            $kredensial = (array) $this->kredensial->nilai($sistem, $lingkungan);
        } catch (Throwable) {
            return ['berhasil' => false, 'gangguan' => false, 'data' => null, 'pesan' => 'Kredensial BPJS VClaim tidak bisa dibuka; periksa KREDENSIAL_KUNCI.'];
        }
        if (! $this->sirkuit->boleh($sistem)) {
            return ['berhasil' => false, 'gangguan' => true, 'data' => null, 'pesan' => 'BPJS VClaim sedang tidak tersambung; dicoba lagi otomatis.'];
        }
        $hasil = $this->klien->get($lingkungan, $kredensial, $path, $pathLog);
        if ($hasil['jawaban']->gangguan()) {
            $pesan = $hasil['jawaban']->galat ?? 'HTTP '.$hasil['jawaban']->kodeHttp;
            $this->sirkuit->gangguan($sistem, $pesan);

            return ['berhasil' => false, 'gangguan' => true, 'data' => null, 'pesan' => $pesan];
        }
        $this->sirkuit->berhasil($sistem);
        $berhasil = $hasil['code'] === '200' && $hasil['terbuka'];
        $pesan = match (true) {
            $berhasil => null,
            ! $hasil['terbuka'] => 'Respons BPJS tidak bisa dibuka (kunci atau timestamp tidak cocok).',
            $hasil['message'] !== null => Penyamar::teks($hasil['message']),
            $hasil['code'] !== null => 'Ditolak BPJS (kode '.$hasil['code'].').',
            default => 'Jawaban BPJS tidak dikenali.',
        };

        return ['berhasil' => $berhasil, 'gangguan' => false, 'data' => $berhasil ? $hasil['response'] : null, 'pesan' => $pesan];
    }
}
