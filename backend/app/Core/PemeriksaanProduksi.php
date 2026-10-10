<?php

namespace App\Core;

use RuntimeException;

/**
 * Syarat konfigurasi yang wajib di luar lokal; aplikasi gagal boot bila dilanggar, bukan
 * diam-diam berjalan tidak aman (PRD 14.2).
 */
final class PemeriksaanProduksi
{
    /** Alat debug yang menampilkan kueri, binding, dan isi permintaan. */
    public const ALAT_DEBUG = [
        'Laravel\Telescope\TelescopeServiceProvider',
        'Barryvdh\Debugbar\ServiceProvider',
    ];

    /** @param  callable(string): bool  $kelasAda */
    public static function periksa(string $lingkungan, bool $debug, bool $cookieAman, callable $kelasAda): void
    {
        if ($lingkungan !== 'local' && ! $cookieAman) {
            throw new RuntimeException('SESSION_SECURE_COOKIE harus true di luar lingkungan lokal.');
        }

        if ($lingkungan !== 'production') {
            return;
        }

        if ($debug) {
            throw new RuntimeException('APP_DEBUG harus false di production.');
        }

        foreach (self::ALAT_DEBUG as $kelas) {
            if ($kelasAda($kelas)) {
                throw new RuntimeException("Alat debug {$kelas} terpasang di production; pasang dengan composer install --no-dev.");
            }
        }
    }
}
