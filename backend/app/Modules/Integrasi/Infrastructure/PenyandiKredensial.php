<?php

namespace App\Modules\Integrasi\Infrastructure;

use App\Core\Http\ApiError;
use Illuminate\Encryption\Encrypter;

/**
 * Enkripsi kolom rahasia kredensial integrasi (K9) dengan kunci khusus `KREDENSIAL_KUNCI` (bukan APP_KEY), AES-256-GCM.
 * Format kunci `base64:<32 bita>`. Id kunci = 8 hex awal SHA-256 kunci, disimpan per baris agar rotasi bisa membaca
 * baris lama dengan `KREDENSIAL_KUNCI_LAMA` (dipisah koma). Vault bisa dipasang kemudian lewat antarmuka yang sama.
 */
final class PenyandiKredensial
{
    /**
     * @param  array<string, string>  $rahasia
     * @return array{teks: string, id_kunci: string}
     */
    public function sandikan(array $rahasia): array
    {
        $kunci = self::urai((string) config('integrasi.kunci'));
        if ($kunci === null) {
            throw self::belumDikonfigurasi();
        }

        return ['teks' => (new Encrypter($kunci, 'aes-256-gcm'))->encryptString((string) json_encode($rahasia)), 'id_kunci' => self::id($kunci)];
    }

    /** @return array<string, string> */
    public function buka(string $teks, string $idKunci): array
    {
        foreach ([(string) config('integrasi.kunci'), ...explode(',', (string) config('integrasi.kunci_lama'))] as $calon) {
            $kunci = self::urai(trim($calon));
            if ($kunci !== null && hash_equals(self::id($kunci), $idKunci)) {
                /** @var array<string, string> */
                return json_decode((new Encrypter($kunci, 'aes-256-gcm'))->decryptString($teks), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        throw self::belumDikonfigurasi();
    }

    public function siap(): bool
    {
        return self::urai((string) config('integrasi.kunci')) !== null;
    }

    public function idAktif(): ?string
    {
        $kunci = self::urai((string) config('integrasi.kunci'));

        return $kunci === null ? null : self::id($kunci);
    }

    public static function belumDikonfigurasi(): ApiError
    {
        return new ApiError(503, 'kredensial_kunci_belum_dikonfigurasi', 'Kunci kredensial integrasi belum dikonfigurasi di server. Hubungi admin.');
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
