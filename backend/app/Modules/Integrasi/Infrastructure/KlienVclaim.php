<?php

namespace App\Modules\Integrasi\Infrastructure;

use App\Modules\Integrasi\Domain\SistemIntegrasi;

/**
 * Panggilan baca VClaim (catatan lapangan §1, §3, §4.1, §10; perlu verifikasi ke katalog resmi BPJS). Respons
 * `response` berupa string terenkripsi: AES-256-CBC dengan kunci = sha256(consId + secretKey + timestamp) (32 bita),
 * IV = 16 bita pertama kunci, lalu LZString EncodedURIComponent. Timestamp yang dipakai adalah timestamp permintaan
 * itu sendiri (bagian dari kunci). Log berisi respons sesudah dekripsi, disamarkan.
 */
final class KlienVclaim
{
    public function __construct(private readonly PanggilanHttp $http) {}

    /**
     * @param  array<string, string>  $kredensial  cons_id, secret_key, user_key
     * @return array{jawaban: JawabanHttp, code: string|null, message: string|null, response: mixed}
     */
    public function get(string $lingkungan, array $kredensial, string $path, ?string $kotakKeluarId = null): array
    {
        $waktu = time();
        $jawaban = $this->http->kirim(SistemIntegrasi::BpjsVclaim, $lingkungan, 'GET', $path, ['headers' => KlienBpjs::header($kredensial, $waktu)]);
        $json = $jawaban->json();
        $meta = KlienBpjs::meta($json);
        $response = $json['response'] ?? null;
        if (is_string($response) && $response !== '') {
            $response = self::buka($response, $kredensial, $waktu);
        }
        $berhasil = $jawaban->kodeHttp === 200 && $meta['code'] === '200';
        $this->http->catat(SistemIntegrasi::BpjsVclaim, $lingkungan, 'GET', $path, $jawaban, $berhasil,
            $berhasil ? null : ($jawaban->galat ?? $meta['message'] ?? 'HTTP '.$jawaban->kodeHttp), null,
            $json === null ? null : ['metaData' => ['code' => $meta['code'], 'message' => $meta['message']], 'response' => $response], $kotakKeluarId);

        return ['jawaban' => $jawaban, 'code' => $meta['code'], 'message' => $meta['message'], 'response' => $response];
    }

    /**
     * Dekripsi `response` VClaim. null = gagal didekripsi (kunci/timestamp tidak cocok atau data rusak).
     *
     * @param  array<string, string>  $kredensial
     */
    public static function buka(string $teks, array $kredensial, int $waktu): mixed
    {
        [$kunci, $iv] = self::kunci($kredensial, $waktu);
        $lz = openssl_decrypt($teks, 'AES-256-CBC', $kunci, 0, $iv);
        if ($lz === false) {
            return null;
        }
        $isi = LzString::dekompres($lz);

        return $isi === null ? null : (json_decode($isi, true) ?? $isi);
    }

    /**
     * Kebalikan buka(); hanya dipakai simulator dan tes.
     *
     * @param  array<string, string>  $kredensial
     */
    public static function tutup(mixed $isi, array $kredensial, int $waktu): string
    {
        [$kunci, $iv] = self::kunci($kredensial, $waktu);

        return (string) openssl_encrypt(LzString::kompres(is_string($isi) ? $isi : (string) json_encode($isi)), 'AES-256-CBC', $kunci, 0, $iv);
    }

    /**
     * @param  array<string, string>  $kredensial
     * @return array{0: string, 1: string}
     */
    private static function kunci(array $kredensial, int $waktu): array
    {
        $kunci = hash('sha256', $kredensial['cons_id'].$kredensial['secret_key'].$waktu, true);

        return [$kunci, substr($kunci, 0, 16)];
    }
}
