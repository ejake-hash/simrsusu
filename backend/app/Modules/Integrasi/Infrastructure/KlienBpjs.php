<?php

namespace App\Modules\Integrasi\Infrastructure;

/**
 * Pondasi BPJS (VClaim, Aplicares) menurut catatan lapangan §1.1 dan §1.4 (perlu verifikasi ke katalog resmi BPJS):
 * header X-cons-id, X-timestamp (epoch UTC detik), X-signature = base64(HMAC-SHA256("{consId}&{timestamp}", secretKey)),
 * user_key. Penentu sukses dari `metaData`/`metadata` (huruf besar-kecil tidak konsisten antar endpoint).
 */
final class KlienBpjs
{
    /**
     * @param  array<string, string>  $kredensial  cons_id, secret_key, user_key
     * @return array<string, string>
     */
    public static function header(array $kredensial, ?int $waktu = null): array
    {
        $timestamp = (string) ($waktu ?? time());

        return [
            'X-cons-id' => $kredensial['cons_id'],
            'X-timestamp' => $timestamp,
            'X-signature' => base64_encode(hash_hmac('sha256', $kredensial['cons_id'].'&'.$timestamp, $kredensial['secret_key'], true)),
            'user_key' => $kredensial['user_key'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array{code: string|null, message: string|null}
     */
    public static function meta(?array $json): array
    {
        $meta = $json['metaData'] ?? $json['metadata'] ?? null;

        return is_array($meta)
            ? ['code' => isset($meta['code']) ? (string) $meta['code'] : null, 'message' => isset($meta['message']) ? (string) $meta['message'] : null]
            : ['code' => null, 'message' => null];
    }

    /**
     * VClaim: sukses = code "200". Kode lain (termasuk 201 yang kadang berarti sukses, catatan §1.4) ditangani per
     * endpoint oleh pemanggilnya.
     *
     * @param  array<string, mixed>|null  $json
     */
    public static function suksesVclaim(?array $json): bool
    {
        return self::meta($json)['code'] === '200';
    }

    /**
     * Aplicares: JSON polos (catatan §24). Kode sukses belum tercatat di catatan lapangan; diterima "1" atau "200"
     * (**perlu verifikasi** ke dokumen resmi Aplicares).
     *
     * @param  array<string, mixed>|null  $json
     */
    public static function suksesAplicares(?array $json): bool
    {
        return in_array(self::meta($json)['code'], ['1', '200'], true);
    }
}
