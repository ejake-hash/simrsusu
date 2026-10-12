<?php

namespace App\Modules\Integrasi\Infrastructure;

use App\Modules\Integrasi\Domain\SistemIntegrasi;
use Illuminate\Support\Facades\Cache;

/**
 * Klien SATUSEHAT (catatan lapangan §15, perlu verifikasi ke dokumentasi resmi Kemenkes). Token OAuth2 client credentials
 * ke `{host}/oauth2/v1/accesstoken?grant_type=client_credentials`, di cache dengan TTL `expires_in` − 60 detik, kunci
 * cache per client_id + lingkungan agar pindah lingkungan tidak memakai token basi. Resource FHIR di `{host}/fhir-r4/v1`,
 * KFA di `{host}/kfa-v2`. Token tidak pernah dicatat.
 */
final class KlienSatusehat
{
    public const PATH_TOKEN = 'oauth2/v1/accesstoken?grant_type=client_credentials';

    public const PATH_FHIR = 'fhir-r4/v1/';

    public const PATH_KFA = 'kfa-v2/';

    public function __construct(private readonly PanggilanHttp $http) {}

    /**
     * Minta token baru (tanpa cache). Log mencatat panggilan tanpa token dan tanpa client_secret.
     *
     * @param  array<string, string>  $kredensial
     * @return array{jawaban: JawabanHttp, token: string|null, berlaku_detik: int|null}
     */
    public function mintaToken(string $lingkungan, array $kredensial, int $batasWaktu, ?string $kotakKeluarId = null): array
    {
        $jawaban = $this->http->kirim(SistemIntegrasi::Satusehat, $lingkungan, 'POST', self::PATH_TOKEN, [
            'form' => ['client_id' => $kredensial['client_id'], 'client_secret' => $kredensial['client_secret']], 'batas_waktu' => $batasWaktu,
        ]);
        $json = $jawaban->json();
        $token = is_string($json['access_token'] ?? null) && $jawaban->kodeHttp === 200 ? $json['access_token'] : null;
        $pesan = $token !== null ? null : ($jawaban->galat ?? (string) ($json['error_description'] ?? $json['error'] ?? 'Token tidak diterbitkan (HTTP '.$jawaban->kodeHttp.').'));
        $this->http->catat(SistemIntegrasi::Satusehat, $lingkungan, 'POST', self::PATH_TOKEN, $jawaban, $token !== null, $pesan,
            ['grant_type' => 'client_credentials'], $json, $kotakKeluarId);

        return ['jawaban' => $jawaban, 'token' => $token, 'berlaku_detik' => is_numeric($json['expires_in'] ?? null) ? (int) $json['expires_in'] : null];
    }

    /** @param  array<string, string>  $kredensial */
    public function token(string $lingkungan, array $kredensial, ?string $kotakKeluarId = null): ?string
    {
        $token = $this->ambilToken($lingkungan, $kredensial, $kotakKeluarId);

        return is_string($token) ? $token : null;
    }

    /**
     * Satu panggilan FHIR/KFA dengan bearer token. Jawaban 401 = token kedaluwarsa atau dicabut: token dibuang dari cache
     * lalu diminta ulang satu kali. `galat_token` terisi bila token tidak bisa diperoleh (jawaban = jawaban permintaan
     * token).
     *
     * @param  array<string, string>  $kredensial
     * @param  array<string, mixed>|null  $isi
     * @return array{jawaban: JawabanHttp, json: array<string, mixed>|null, galat_token: string|null}
     */
    public function panggil(string $lingkungan, array $kredensial, string $metode, string $path, ?array $isi = null, ?string $kotakKeluarId = null,
        bool $ulang = false): array
    {
        $token = $this->ambilToken($lingkungan, $kredensial, $kotakKeluarId);
        if ($token instanceof JawabanHttp) {
            return ['jawaban' => $token, 'json' => null,
                'galat_token' => $token->galat ?? 'Token SATUSEHAT tidak diterbitkan (HTTP '.$token->kodeHttp.'): periksa kredensial.'];
        }
        $jawaban = $this->http->kirim(SistemIntegrasi::Satusehat, $lingkungan, $metode, $path,
            ['headers' => ['Authorization' => 'Bearer '.$token], ...($isi === null ? [] : ['json' => $isi])]);
        $json = $jawaban->json();
        $berhasil = $jawaban->kodeHttp !== null && $jawaban->kodeHttp >= 200 && $jawaban->kodeHttp < 300;
        // Path dicatat tanpa query (Penyamar::path): query pencarian bisa memuat NIK.
        $this->http->catat(SistemIntegrasi::Satusehat, $lingkungan, $metode, $path, $jawaban, $berhasil,
            $berhasil ? null : self::pesan($jawaban, $json), $isi, $json, $kotakKeluarId);
        if ($jawaban->kodeHttp === 401 && ! $ulang) {
            Cache::forget(self::kunciToken($lingkungan, $kredensial));

            return $this->panggil($lingkungan, $kredensial, $metode, $path, $isi, $kotakKeluarId, true);
        }

        return ['jawaban' => $jawaban, 'json' => $json, 'galat_token' => null];
    }

    /**
     * Pesan dari OperationOutcome (issue pertama: details.text, diagnostics, atau code), belum disamarkan.
     *
     * @param  array<string, mixed>|null  $json
     */
    public static function pesan(JawabanHttp $jawaban, ?array $json): string
    {
        $issue = is_array($json['issue'][0] ?? null) ? $json['issue'][0] : [];
        $teks = $issue['details']['text'] ?? $issue['diagnostics'] ?? $issue['code'] ?? null;

        return $jawaban->galat ?? (is_string($teks) ? 'SATUSEHAT: '.$teks.' (HTTP '.$jawaban->kodeHttp.')' : 'Jawaban SATUSEHAT HTTP '.$jawaban->kodeHttp.'.');
    }

    /**
     * Duplikat (kode 20002, catatan lapangan §19 dan §22.4): resource dengan identifier yang sama sudah terdaftar. Letak
     * kode di OperationOutcome belum tercatat, jadi dicari di seluruh issue, juga issue.code `duplicate` (FHIR).
     *
     * @param  array<string, mixed>|null  $json
     */
    public static function duplikat(JawabanHttp $jawaban, ?array $json): bool
    {
        if ($jawaban->kodeHttp === null || $jawaban->kodeHttp < 400 || $jawaban->kodeHttp >= 500 || ! is_array($json['issue'] ?? null)) {
            return false;
        }
        foreach ($json['issue'] as $issue) {
            // Batas: 20002 di dalam id/UUID (mis. `1a220002-…`) bukan kode duplikat.
            if (is_array($issue) && (($issue['code'] ?? null) === 'duplicate' || preg_match('/(?<![0-9A-Za-z-])20002(?![0-9A-Za-z-])/', (string) json_encode($issue)) === 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $kredensial
     * @return string|JawabanHttp token, atau jawaban permintaan token yang gagal
     */
    private function ambilToken(string $lingkungan, array $kredensial, ?string $kotakKeluarId): string|JawabanHttp
    {
        $kunci = self::kunciToken($lingkungan, $kredensial);
        $token = Cache::get($kunci);
        if (is_string($token)) {
            return $token;
        }
        $hasil = $this->mintaToken($lingkungan, $kredensial, (int) config('integrasi.batas_waktu'), $kotakKeluarId);
        if ($hasil['token'] === null) {
            return $hasil['jawaban'];
        }
        if (($hasil['berlaku_detik'] ?? 0) > 120) {
            Cache::put($kunci, $hasil['token'], $hasil['berlaku_detik'] - 60);
        }

        return $hasil['token'];
    }

    /** @param  array<string, string>  $kredensial */
    private static function kunciToken(string $lingkungan, array $kredensial): string
    {
        return 'integrasi:satusehat:token:'.substr(sha1($kredensial['client_id'].'|'.$lingkungan), 0, 16);
    }
}
