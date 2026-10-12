<?php

namespace App\Modules\Integrasi\Infrastructure;

use App\Modules\Integrasi\Application\KelolaKredensial;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Simulator sistem luar dalam repo (K8; tiket 1.9 butir 6), dipakai PanggilanHttp bila INTEGRASI_SIMULATOR aktif:
 * tidak ada permintaan yang keluar jaringan. Perilaku mengikuti catatan lapangan (perlu verifikasi): header BPJS
 * wajib dan tanda tangan berbentuk base64 (§1.1), `metaData`/`metadata` (§1.4), token OAuth2 SATUSEHAT (§15.2).
 * Mode per sistem untuk menguji ketahanan: `normal`, `mati` (tidak tersambung), `gangguan` (HTTP 503), `tolak`
 * (kredensial ditolak). Penangan kiriman memasang jawabannya sendiri lewat jawabanTambahan().
 */
final class SimulatorIntegrasi
{
    public const MODE = ['normal', 'mati', 'gangguan', 'tolak'];

    /** @var array<string, callable(string, string, array<string, mixed>): ?JawabanHttp> */
    private static array $tambahan = [];

    public static function aktif(): bool
    {
        return (bool) config('integrasi.simulator') && ! app()->isProduction();
    }

    public static function atur(SistemIntegrasi $sistem, string $mode): void
    {
        Cache::forever('integrasi:simulator:'.$sistem->value, $mode);
    }

    public static function mode(SistemIntegrasi $sistem): string
    {
        $mode = Cache::get('integrasi:simulator:'.$sistem->value);

        return in_array($mode, self::MODE, true) ? $mode : 'normal';
    }

    /**
     * Jawaban untuk path tertentu dari penangan kiriman (mis. Location SATUSEHAT di PR berikutnya).
     *
     * @param  callable(string $metode, string $path, array<string, mixed> $isi): ?JawabanHttp  $jawab
     */
    public static function jawabanTambahan(SistemIntegrasi $sistem, callable $jawab): void
    {
        self::$tambahan[$sistem->value] = $jawab;
    }

    /**
     * @param  array<string, string>  $header
     * @param  array<string, mixed>  $isi
     */
    public function jawab(SistemIntegrasi $sistem, string $lingkungan, string $metode, string $path, array $header, array $isi): JawabanHttp
    {
        $latensi = random_int(5, 40);
        $path = '/'.ltrim((string) strtok($path, '?'), '/');

        return match (self::mode($sistem)) {
            'mati' => new JawabanHttp(null, null, $latensi, 'Tidak tersambung.'),
            'gangguan' => new JawabanHttp(503, '<html><body>Service Unavailable</body></html>', $latensi, null),
            'tolak' => $sistem === SistemIntegrasi::Satusehat
                ? self::json(401, ['error' => 'invalid_client', 'error_description' => 'Invalid client credentials'], $latensi)
                : self::json(200, ['metaData' => ['code' => '401', 'message' => 'Unauthorized']], $latensi),
            default => $this->normal($sistem, $lingkungan, $metode, $path, $header, $isi, $latensi),
        };
    }

    /**
     * @param  array<string, string>  $header
     * @param  array<string, mixed>  $isi
     */
    private function normal(SistemIntegrasi $sistem, string $lingkungan, string $metode, string $path, array $header, array $isi, int $latensi): JawabanHttp
    {
        if ($sistem->kodeBpjs()) {
            foreach (['X-cons-id', 'X-timestamp', 'X-signature', 'user_key'] as $h) {
                if (($header[$h] ?? '') === '') {
                    return self::json(200, ['metaData' => ['code' => '401', 'message' => "Header $h wajib"]], $latensi);
                }
            }
            if (base64_decode($header['X-signature'], true) === false || strlen(base64_decode($header['X-signature'])) !== 32) {
                return self::json(200, ['metaData' => ['code' => '401', 'message' => 'Signature tidak valid']], $latensi);
            }
        }
        $tambahan = self::$tambahan[$sistem->value] ?? null;
        if ($tambahan !== null && ($j = $tambahan($metode, $path, $isi)) !== null) {
            return $j;
        }

        if ($sistem === SistemIntegrasi::BpjsVclaim && $metode === 'GET') {
            return $this->vclaim($lingkungan, $path, $header, $latensi);
        }

        return match (true) {
            $sistem === SistemIntegrasi::Aplicares && $metode === 'GET' && $path === '/ref/kelas' => self::json(200, ['metadata' => ['code' => 1, 'message' => 'OK']], $latensi),
            $sistem === SistemIntegrasi::Satusehat && $metode === 'POST' && $path === '/oauth2/v1/accesstoken' => ($isi['client_id'] ?? '') === '' || ($isi['client_secret'] ?? '') === ''
                    ? self::json(401, ['error' => 'invalid_client', 'error_description' => 'Invalid client credentials'], $latensi)
                    : self::json(200, ['access_token' => Str::random(40), 'expires_in' => '14399', 'token_type' => 'BearerToken', 'status' => 'approved'], $latensi),
            default => self::json(404, ['metaData' => ['code' => '404', 'message' => 'Simulator: path tidak dikenal']], $latensi),
        };
    }

    /**
     * VClaim baca dengan `response` terenkripsi memakai kredensial tersimpan dan X-timestamp permintaan (catatan §1.3).
     * Data sintetis: peserta 9990000000001 / NIK 9900000000000001; rujukan SIMFKTP001 (FKTP) dan SIMRS001 (hanya di RS,
     * dibungkus array). Kode untuk data tidak ditemukan ("201") adalah asumsi simulator, perlu verifikasi.
     *
     * @param  array<string, string>  $header
     */
    private function vclaim(string $lingkungan, string $path, array $header, int $latensi): JawabanHttp
    {
        $peserta = ['noKartu' => '9990000000001', 'nik' => '9900000000000001', 'nama' => 'PESERTA SINTETIS', 'sex' => 'P', 'tglLahir' => '1990-01-01',
            'hakKelas' => ['kode' => '3', 'keterangan' => 'KELAS III'], 'jenisPeserta' => ['kode' => '0', 'keterangan' => 'SINTETIS'],
            'statusPeserta' => ['kode' => '0', 'keterangan' => 'AKTIF']];
        $rujukan = fn (string $no) => ['noKunjungan' => $no, 'tglKunjungan' => '2026-10-01', 'provPerujuk' => ['kode' => 'SIM0001', 'nama' => 'FASKES SINTETIS'],
            'peserta' => $peserta, 'diagnosa' => ['kode' => 'Z00.0', 'nama' => 'SINTETIS'], 'poliRujukan' => ['kode' => 'INT', 'nama' => 'PENYAKIT DALAM']];
        $isi = match (true) {
            $path === '/referensi/propinsi' => ['list' => [['kode' => '99', 'nama' => 'PROVINSI SINTETIS']]],
            preg_match('#^/referensi/#', $path) === 1 => ['list' => []],
            preg_match('#^/Peserta/(nik/9900000000000001|nokartu/9990000000001)/tglSEP/#', $path) === 1 => ['peserta' => $peserta],
            $path === '/Rujukan/SIMFKTP001' => ['rujukan' => $rujukan('SIMFKTP001')],
            $path === '/Rujukan/RS/SIMRS001' => ['rujukan' => [$rujukan('SIMRS001')]],
            default => null,
        };
        if ($isi === null) {
            return self::json(200, ['metaData' => ['code' => '201', 'message' => 'Data tidak ditemukan'], 'response' => null], $latensi);
        }
        $kredensial = app(KelolaKredensial::class)->nilai(SistemIntegrasi::BpjsVclaim, $lingkungan) ?? [];
        if (($kredensial['cons_id'] ?? null) !== $header['X-cons-id']) {
            return self::json(200, ['metaData' => ['code' => '401', 'message' => 'Consumer ID tidak dikenal'], 'response' => null], $latensi);
        }

        return self::json(200, ['metaData' => ['code' => '200', 'message' => 'Sukses'],
            'response' => KlienVclaim::tutup($isi, $kredensial, (int) $header['X-timestamp'])], $latensi);
    }

    /** @param  array<string, mixed>  $isi */
    private static function json(int $kode, array $isi, int $latensi): JawabanHttp
    {
        return new JawabanHttp($kode, (string) json_encode($isi), $latensi, null);
    }
}
