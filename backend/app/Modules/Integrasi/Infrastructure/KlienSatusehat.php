<?php

namespace App\Modules\Integrasi\Infrastructure;

use App\Modules\Integrasi\Domain\SistemIntegrasi;
use Illuminate\Support\Facades\Cache;

/**
 * Token OAuth2 SATUSEHAT (catatan lapangan §15.2, perlu verifikasi ke dokumentasi resmi Kemenkes): client credentials ke
 * `{host}/oauth2/v1/accesstoken?grant_type=client_credentials`, token di cache dengan TTL `expires_in` − 60 detik, kunci
 * cache per client_id + lingkungan agar pindah lingkungan tidak memakai token basi. Token tidak pernah dicatat.
 */
final class KlienSatusehat
{
    public const PATH_TOKEN = 'oauth2/v1/accesstoken?grant_type=client_credentials';

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
        $kunci = 'integrasi:satusehat:token:'.substr(sha1($kredensial['client_id'].'|'.$lingkungan), 0, 16);
        $token = Cache::get($kunci);
        if (is_string($token)) {
            return $token;
        }
        $hasil = $this->mintaToken($lingkungan, $kredensial, (int) config('integrasi.batas_waktu'), $kotakKeluarId);
        if ($hasil['token'] !== null && ($hasil['berlaku_detik'] ?? 0) > 120) {
            Cache::put($kunci, $hasil['token'], $hasil['berlaku_detik'] - 60);
        }

        return $hasil['token'];
    }
}
