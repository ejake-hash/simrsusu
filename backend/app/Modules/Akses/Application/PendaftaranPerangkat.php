<?php

namespace App\Modules\Akses\Application;

use Illuminate\Http\Request;

/**
 * Apakah permintaan datang dari perangkat pribadi terdaftar (batas diam 60 menit, PRD 14.2).
 * Registri perangkat belum ada; implementasi bawaan selalu menjawab tidak (lihat ADR 0002).
 */
interface PendaftaranPerangkat
{
    public function terdaftar(Request $request): bool;
}
