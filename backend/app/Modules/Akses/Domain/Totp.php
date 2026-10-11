<?php

namespace App\Modules\Akses\Domain;

use InvalidArgumentException;

/**
 * TOTP RFC 6238 (HMAC-SHA1, periode 30 detik) dan Base32 RFC 4648 untuk rahasia di URI otpauth.
 * Murni tanpa I/O; anti pakai ulang (langkah terakhir) diurus pemanggil.
 */
final class Totp
{
    public const PERIODE = 30;

    public const DIGIT = 6;

    private const ALFABET_BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Rahasia baru 160 bit (panjang kunci HMAC-SHA1 yang disarankan RFC 4226), dalam Base32. */
    public static function rahasiaBaru(): string
    {
        return self::base32Enkode(random_bytes(20));
    }

    public static function langkah(int $detikUnix): int
    {
        return intdiv($detikUnix, self::PERIODE);
    }

    public static function kode(string $rahasiaBase32, int $langkah, int $digit = self::DIGIT): string
    {
        $hmac = hash_hmac('sha1', pack('J', $langkah), self::base32Dekode($rahasiaBase32), true);
        $offset = ord($hmac[19]) & 0x0F;
        $angka = ((ord($hmac[$offset]) & 0x7F) << 24)
            | (ord($hmac[$offset + 1]) << 16)
            | (ord($hmac[$offset + 2]) << 8)
            | ord($hmac[$offset + 3]);

        return str_pad((string) ($angka % 10 ** $digit), $digit, '0', STR_PAD_LEFT);
    }

    /**
     * Langkah yang cocok dalam toleransi ±$toleransi periode, atau null. Dibandingkan dengan
     * hash_equals agar waktu tidak membocorkan digit yang benar.
     */
    public static function cocokkan(string $rahasiaBase32, string $kode, int $detikUnix, int $toleransi = 1): ?int
    {
        if (preg_match('/^\d{'.self::DIGIT.'}$/', $kode) !== 1) {
            return null;
        }

        $sekarang = self::langkah($detikUnix);
        $cocok = null;
        for ($l = $sekarang - $toleransi; $l <= $sekarang + $toleransi; $l++) {
            if (hash_equals(self::kode($rahasiaBase32, $l), $kode)) {
                $cocok ??= $l;
            }
        }

        return $cocok;
    }

    public static function uriOtpauth(string $rahasiaBase32, string $penerbit, string $akun): string
    {
        $label = rawurlencode($penerbit).':'.rawurlencode($akun);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $rahasiaBase32,
            'issuer' => $penerbit,
            'algorithm' => 'SHA1',
            'digits' => self::DIGIT,
            'period' => self::PERIODE,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Enkode(string $biner): string
    {
        $bit = '';
        foreach (str_split($biner) as $b) {
            $bit .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }

        $hasil = '';
        foreach (str_split($bit, 5) as $potong) {
            $hasil .= self::ALFABET_BASE32[bindec(str_pad($potong, 5, '0'))];
        }

        return $hasil;
    }

    public static function base32Dekode(string $teks): string
    {
        $teks = strtoupper(rtrim(str_replace(' ', '', $teks), '='));
        $bit = '';
        foreach (str_split($teks) as $c) {
            $nilai = strpos(self::ALFABET_BASE32, $c);
            if ($nilai === false) {
                throw new InvalidArgumentException('Rahasia Base32 tidak valid.');
            }
            $bit .= str_pad(decbin($nilai), 5, '0', STR_PAD_LEFT);
        }

        $hasil = '';
        foreach (str_split($bit, 8) as $byte) {
            if (strlen($byte) === 8) {
                $hasil .= chr(bindec($byte));
            }
        }

        return $hasil;
    }
}
