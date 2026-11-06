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
 * Parameter path divalidasi ketat sebelum masuk URL. Hasil gagal membawa `kode` BPJS (penolakan isi) atau `penyebab`
 * (sistem tidak bisa dipakai: integrasi_nonaktif, kredensial_belum_lengkap, circuit_breaker_terbuka, batas_waktu,
 * tidak_tersambung; kontrak v0.21.0). Tulis: insert SEP (tiket 2.7) lewat outbox; rujukan keluar, kontrol, SPRI menyusul.
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
     * @return array{berhasil: bool, gangguan: bool, data: array<string, mixed>|null, pesan: string|null, kode: string|null, penyebab: string|null}
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
     * @return array{berhasil: bool, data: array<string, mixed>|null, pesan: string|null, asal: string|null, kode: string|null, penyebab: string|null}
     */
    public function rujukan(string $nomor, ?string $asal = null): array
    {
        if (preg_match('/^[A-Za-z0-9]{1,40}$/', $nomor) !== 1) {
            throw new InvalidArgumentException('Nomor rujukan tidak valid.');
        }
        $hasil = null;
        $jalur = array_filter(['1' => ["Rujukan/$nomor", 'Rujukan/***'], '2' => ["Rujukan/RS/$nomor", 'Rujukan/RS/***']],
            fn (int $k) => $asal === null || $asal === "$k", ARRAY_FILTER_USE_KEY);
        foreach ($jalur as $asal => [$path, $pathLog]) {
            $hasil = $this->baca($path, $pathLog);
            $rujukan = $hasil['data']['rujukan'] ?? null;
            if (is_array($rujukan) && array_is_list($rujukan)) {
                $rujukan = $rujukan[0] ?? null;
            }
            if ($hasil['berhasil'] && is_array($rujukan)) {
                return ['berhasil' => true, 'data' => $rujukan, 'pesan' => null, 'asal' => (string) $asal, 'kode' => null, 'penyebab' => null];
            }
            if ($hasil['penyebab'] !== null) {
                return [...$hasil, 'data' => null, 'asal' => null];
            }
        }

        return ['berhasil' => false, 'data' => null, 'pesan' => $hasil['pesan'] ?? 'Rujukan tidak ditemukan.', 'asal' => null,
            'kode' => $hasil['kode'] ?? null, 'penyebab' => null];
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

    /**
     * Insert SEP (catatan §5.1–§5.2) dari penangan kiriman outbox: lingkungan dan kredensial sudah dipegang pekerja,
     * circuit breaker diurus PengirimKiriman. `t_sep` = isi `request.t_sep`.
     *
     * @param  array<string, string>  $kredensial
     * @param  array<string, mixed>  $tSep
     * @return array{gangguan: bool, berhasil: bool, kode: string|null, pesan: string|null, no_sep: string|null}
     */
    public function insertSep(string $lingkungan, array $kredensial, array $tSep, string $kirimanId): array
    {
        $hasil = $this->klien->post($lingkungan, $kredensial, 'SEP/2.0/insert', ['request' => ['t_sep' => $tSep]], $kirimanId);
        $j = $hasil['jawaban'];
        if ($j->gangguan()) {
            return ['gangguan' => true, 'berhasil' => false, 'kode' => null, 'pesan' => $j->galat ?? 'HTTP '.$j->kodeHttp, 'no_sep' => null];
        }
        $noSep = $hasil['response']['sep']['noSep'] ?? null;
        $berhasil = $hasil['code'] === '200' && $hasil['terbuka'] && is_string($noSep) && $noSep !== '';

        return ['gangguan' => false, 'berhasil' => $berhasil, 'kode' => $hasil['code'], 'no_sep' => $berhasil ? $noSep : null,
            'pesan' => $berhasil ? null : ($hasil['message'] ?? 'Jawaban BPJS tidak dikenali.')];
    }

    /** @return array{berhasil: bool, gangguan: bool, data: mixed, pesan: string|null, kode: string|null, penyebab: string|null} */
    private function baca(string $path, ?string $pathLog = null): array
    {
        $sistem = SistemIntegrasi::BpjsVclaim;
        $gagal = fn (string $penyebab, string $pesan) => ['berhasil' => false, 'gangguan' => in_array($penyebab, ['circuit_breaker_terbuka', 'batas_waktu', 'tidak_tersambung'], true),
            'data' => null, 'pesan' => $pesan, 'kode' => null, 'penyebab' => $penyebab];
        $lingkungan = $this->kredensial->lingkunganAktif($sistem);
        if ($lingkungan === null) {
            return $gagal('integrasi_nonaktif', 'Integrasi BPJS VClaim belum diaktifkan.');
        }
        // Kredensial dibuka sebelum mengambil jatah circuit breaker: kegagalan di sini tidak menahan jatah setengah terbuka.
        try {
            $kredensial = (array) $this->kredensial->nilai($sistem, $lingkungan);
        } catch (Throwable) {
            return $gagal('kredensial_belum_lengkap', 'Kredensial BPJS VClaim tidak bisa dibuka; periksa KREDENSIAL_KUNCI.');
        }
        if ($this->kredensial->kurang($sistem, $lingkungan) !== []) {
            return $gagal('kredensial_belum_lengkap', 'Kredensial BPJS VClaim lingkungan aktif belum lengkap.');
        }
        if (! $this->sirkuit->boleh($sistem)) {
            return $gagal('circuit_breaker_terbuka', 'BPJS VClaim sedang tidak tersambung; dicoba lagi otomatis.');
        }
        $hasil = $this->klien->get($lingkungan, $kredensial, $path, $pathLog);
        if ($hasil['jawaban']->gangguan()) {
            $pesan = $hasil['jawaban']->galat ?? 'HTTP '.$hasil['jawaban']->kodeHttp;
            $this->sirkuit->gangguan($sistem, $pesan);

            return $gagal($hasil['jawaban']->galat === 'Batas waktu habis.' ? 'batas_waktu' : 'tidak_tersambung', $pesan);
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

        if (! $hasil['terbuka']) {
            return $gagal('kredensial_belum_lengkap', (string) $pesan);
        }

        return ['berhasil' => $berhasil, 'gangguan' => false, 'data' => $berhasil ? $hasil['response'] : null, 'pesan' => $pesan,
            'kode' => $hasil['code'], 'penyebab' => null];
    }
}
