<?php

namespace App\Modules\Akses\Application;

use App\Core\Http\ApiError;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Batas laju sandi salah per akun (ganti sandi, aktivasi TTE; PRD 14.2). Hitungan dinaikkan sebelum Argon2, jadi
 * permintaan paralel tidak bisa sama-sama lolos cek batas; sandi cocok menghapus hitungan.
 */
final class BatasSandiSalah
{
    /** @throws ApiError 429 bila melewati batas, atau $salah bila sandi tidak cocok */
    public static function periksa(string $kunci, int $batas, int $jendelaDetik, string $sandi, string $hash, ApiError $salah): void
    {
        if (RateLimiter::hit($kunci, $jendelaDetik) > $batas) {
            throw new ApiError(429, 'terlalu_banyak_permintaan', 'Terlalu banyak percobaan. Coba lagi sebentar lagi.', [],
                ['Retry-After' => (string) RateLimiter::availableIn($kunci)]);
        }
        if (! Hash::check($sandi, $hash)) {
            throw $salah;
        }
        RateLimiter::clear($kunci);
    }
}
