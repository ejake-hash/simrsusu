<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Modules\Akses\Application\PohonMenu;
use App\Modules\Akses\Domain\Cakupan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** GET /api/v1/menu: pohon menu + izin per node untuk matriks peran dan pengecualian per akun. */
final class MenuController
{
    public function __invoke(PohonMenu $pohon): JsonResponse
    {
        // Dipakai dua halaman: cukup salah satu izin lihat. Penolakan lewat authorize agar kode lapis 5 tidak tertelan.
        if (! Gate::allows('pengaturan.peran.lihat', Cakupan::rs())) {
            Gate::authorize('pengaturan.akun.lihat', Cakupan::rs());
        }

        return new JsonResponse(['data' => $pohon->semua()]);
    }
}
