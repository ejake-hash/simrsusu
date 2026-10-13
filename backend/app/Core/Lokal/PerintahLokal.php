<?php

namespace App\Core\Lokal;

use Illuminate\Foundation\Application;
use Illuminate\Support\Env;

/**
 * Penjaga perintah `lokal:*` (tiket 1.16): tidak didaftarkan dan tidak berjalan di production.
 * Sebelum .env ada, lingkungan aplikasi jatuh ke bawaan `production` (config/app.php), jadi yang dipakai
 * variabel APP_ENV proses; begitu .env ada, lingkungan aplikasi dan isi berkasnya.
 */
final class PerintahLokal
{
    public static function diizinkan(Application $app): bool
    {
        $berkas = $app->environmentFilePath();
        if (is_file($berkas)) {
            return ! $app->isProduction() && ! self::berkasProduksi($berkas);
        }

        return Env::get('APP_ENV') !== 'production' && ! ($app->configurationIsCached() && $app->isProduction());
    }

    public static function berkasProduksi(string $berkas): bool
    {
        $isi = (string) @file_get_contents($berkas);

        return preg_match('/^\s*(?:export\s+)?APP_ENV\s*=\s*["\']?production["\']?\s*(?:#.*)?$/mi', $isi) === 1;
    }
}
