<?php

namespace App\Core\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Akun dari sesi yang masih sah, untuk rute publik yang mengirim lebih banyak data kepada pemegang izin
 * (mis. GET /profil-rs). Rute ini tidak melewati middleware sesi, jadi aturan sesi (aktif, batas diam,
 * pencabutan; ADR 0002) diperiksa di sini tanpa mengakhiri sesi. Diimplementasikan modul Akses.
 */
interface PenggunaSesi
{
    public function dari(Request $request): ?Authenticatable;
}
