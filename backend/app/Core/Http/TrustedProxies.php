<?php

namespace App\Core\Http;

use Illuminate\Http\Middleware\TrustProxies;

/** TrustProxies dengan daftar proksi dari config/jaringan.php, dibaca per permintaan. */
final class TrustedProxies extends TrustProxies
{
    protected function proxies(): ?array
    {
        $proksi = config('jaringan.trusted_proxies');

        return is_array($proksi) && $proksi !== [] ? $proksi : null;
    }
}
