<?php

namespace App\Modules\Akses\Infrastructure;

use App\Modules\Akses\Application\PendaftaranPerangkat;
use Illuminate\Http\Request;

/** Bawaan sampai registri perangkat (MDM) dibangun: semua perangkat dianggap bersama (15 menit). */
final class PerangkatBelumTerdaftar implements PendaftaranPerangkat
{
    public function terdaftar(Request $request): bool
    {
        return false;
    }
}
