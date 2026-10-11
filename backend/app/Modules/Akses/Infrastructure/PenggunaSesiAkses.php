<?php

namespace App\Modules\Akses\Infrastructure;

use App\Core\Http\PenggunaSesi;
use App\Modules\Akses\Http\Middleware\SesiPengguna;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Akun dari cookie sesi bila masih sah menurut aturan yang sama dengan SesiPengguna; tidak mengubah sesi. */
final class PenggunaSesiAkses implements PenggunaSesi
{
    public function dari(Request $request): ?Authenticatable
    {
        if (! $request->hasSession()) {
            return null;
        }
        $pengguna = Auth::guard('web')->user();

        return $pengguna instanceof Pengguna && SesiPengguna::alasanBerakhir($request, $pengguna) === null ? $pengguna : null;
    }
}
