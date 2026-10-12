<?php

namespace App\Modules\Integrasi\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use App\Modules\Integrasi\Infrastructure\JawabanHttp;
use App\Modules\Integrasi\Infrastructure\KlienBpjs;
use App\Modules\Integrasi\Infrastructure\KlienSatusehat;
use App\Modules\Integrasi\Infrastructure\PanggilanHttp;
use App\Modules\Integrasi\Infrastructure\Penyamar;
use Illuminate\Support\Facades\DB;

/**
 * POST /integrasi/sistem/{kode}/uji-koneksi (kontrak v0.13.0): satu panggilan baca dengan kredensial tersimpan, tidak
 * lewat outbox dan tidak dipengaruhi circuit breaker. Panggilan uji (catatan lapangan, perlu verifikasi): VClaim
 * `referensi/propinsi` (§10, §14.2), Aplicares `ref/kelas` (§24), SATUSEHAT token OAuth2 (§15.2).
 */
final class UjiKoneksi
{
    public function __construct(
        private readonly KelolaKredensial $kredensial,
        private readonly PanggilanHttp $http,
        private readonly KlienSatusehat $satusehat,
        private readonly CatatAktivitas $audit,
    ) {}

    /** @return array<string, mixed> HasilUjiKoneksi */
    public function uji(SistemIntegrasi $sistem, ?string $lingkungan, string $olehId): array
    {
        $lingkungan ??= $this->kredensial->lingkunganAktif($sistem);
        if ($lingkungan === null) {
            throw new ApiError(422, 'lingkungan_belum_aktif', 'Pilih lingkungan atau aktifkan salah satu lingkungan dulu.', [['kolom' => 'lingkungan', 'pesan' => 'Belum ada lingkungan aktif.']]);
        }
        $kurang = $this->kredensial->kurang($sistem, $lingkungan);
        if ($kurang !== []) {
            throw new ApiError(422, 'kredensial_belum_lengkap', 'Lengkapi kredensial lingkungan ini dulu.',
                array_map(fn ($n) => ['kolom' => "kolom.$n", 'pesan' => 'Wajib diisi.'], $kurang));
        }
        $nilai = (array) $this->kredensial->nilai($sistem, $lingkungan);
        $batas = (int) config('integrasi.batas_waktu_uji');

        [$jawaban, $berhasil, $alasan] = match ($sistem) {
            SistemIntegrasi::Satusehat => $this->ujiSatusehat($lingkungan, $nilai, $batas),
            default => $this->ujiBpjs($sistem, $lingkungan, $nilai, $batas),
        };
        $hasil = [
            'lingkungan' => $lingkungan, 'berhasil' => $berhasil, 'kode_http' => $jawaban->kodeHttp, 'latensi_ms' => $jawaban->latensiMs,
            'pesan' => $berhasil ? 'Tersambung ke '.$sistem->nama().' ('.$lingkungan.').' : (string) Penyamar::teks($alasan), 'pada' => now()->toIso8601String(),
        ];

        DB::transaction(function () use ($sistem, $hasil, $olehId) {
            DB::table('integrasi.status_sistem')->where('tujuan', $sistem->value)->update(['uji_koneksi_terakhir' => json_encode($hasil), 'diubah_pada' => now()]);
            $this->audit->catat('integrasi.uji_koneksi', $olehId, 'integrasi.status_sistem', null, [
                'sistem' => $sistem->value, 'lingkungan' => $hasil['lingkungan'], 'berhasil' => $hasil['berhasil'], 'kode_http' => $hasil['kode_http'],
            ]);
        });

        return $hasil;
    }

    /**
     * @param  array<string, string>  $nilai
     * @return array{0: JawabanHttp, 1: bool, 2: string|null}
     */
    private function ujiBpjs(SistemIntegrasi $sistem, string $lingkungan, array $nilai, int $batas): array
    {
        $path = $sistem === SistemIntegrasi::BpjsVclaim ? 'referensi/propinsi' : 'ref/kelas';
        $jawaban = $this->http->kirim($sistem, $lingkungan, 'GET', $path, ['headers' => KlienBpjs::header($nilai), 'batas_waktu' => $batas]);
        $json = $jawaban->json();
        $meta = KlienBpjs::meta($json);
        $berhasil = $jawaban->kodeHttp === 200 && ($sistem === SistemIntegrasi::BpjsVclaim ? KlienBpjs::suksesVclaim($json) : KlienBpjs::suksesAplicares($json));
        $alasan = $berhasil ? null : self::alasan($jawaban, $meta['message'] !== null ? 'BPJS: '.$meta['message'] : null);
        // Isi `response` VClaim terenkripsi; uji koneksi cukup membaca metaData.
        $this->http->catat($sistem, $lingkungan, 'GET', $path, $jawaban, $berhasil, $alasan, null, $json === null ? null : ['metaData' => $json['metaData'] ?? $json['metadata'] ?? null]);

        return [$jawaban, $berhasil, $alasan];
    }

    /**
     * @param  array<string, string>  $nilai
     * @return array{0: JawabanHttp, 1: bool, 2: string|null}
     */
    private function ujiSatusehat(string $lingkungan, array $nilai, int $batas): array
    {
        $hasil = $this->satusehat->mintaToken($lingkungan, $nilai, $batas);
        $json = $hasil['jawaban']->json();
        $berhasil = $hasil['token'] !== null;

        return [$hasil['jawaban'], $berhasil, $berhasil ? null : self::alasan($hasil['jawaban'], isset($json['error']) ? 'SATUSEHAT: '.($json['error_description'] ?? $json['error']) : null)];
    }

    private static function alasan(JawabanHttp $j, ?string $pesanLuar): string
    {
        return match (true) {
            $j->galat !== null => $j->galat,
            in_array($j->kodeHttp, [401, 403], true) => 'Ditolak sistem luar (HTTP '.$j->kodeHttp.'): periksa kredensial.',
            $pesanLuar !== null => $pesanLuar,
            default => 'Jawaban tidak dikenali (HTTP '.$j->kodeHttp.').',
        };
    }
}
