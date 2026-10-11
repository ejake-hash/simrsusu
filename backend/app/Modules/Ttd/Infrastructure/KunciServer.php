<?php

namespace App\Modules\Ttd\Infrastructure;

use App\Core\Http\ApiError;

/**
 * Kunci server TTE (aturan rinci 1.6): lada PIN dari env/vault, tidak pernah di DB. `TTD_KUNCI` = kunci aktif,
 * `TTD_KUNCI_LAMA` = daftar kunci lama (dipisah koma) untuk kredensial yang dibungkus sebelum rotasi. Format
 * `base64:<32 bita>`. Id kunci = 8 hex awal SHA-256 kunci, disimpan di parameter_kdf kredensial.
 */
final class KunciServer
{
    /** @return array{id: string, kunci: string} */
    public function aktif(): array
    {
        $kunci = self::urai((string) config('ttd.kunci'));
        if ($kunci === null) {
            throw new ApiError(503, 'ttd_belum_dikonfigurasi', 'Tanda tangan elektronik belum dikonfigurasi di server. Hubungi admin.');
        }

        return ['id' => self::id($kunci), 'kunci' => $kunci];
    }

    public function menurutId(string $id): ?string
    {
        foreach ([(string) config('ttd.kunci'), ...explode(',', (string) config('ttd.kunci_lama'))] as $teks) {
            $kunci = self::urai(trim($teks));
            if ($kunci !== null && hash_equals(self::id($kunci), $id)) {
                return $kunci;
            }
        }

        return null;
    }

    private static function urai(string $teks): ?string
    {
        $kunci = str_starts_with($teks, 'base64:') ? base64_decode(substr($teks, 7), true) : false;

        return $kunci !== false && strlen($kunci) === 32 ? $kunci : null;
    }

    private static function id(string $kunci): string
    {
        return substr(hash('sha256', $kunci), 0, 8);
    }
}
