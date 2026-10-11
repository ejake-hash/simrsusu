<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Modules\Akses\Application\PohonMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** GET /api/v1/menu: pohon menu + izin per node untuk matriks peran dan pengecualian per akun. */
final class MenuController
{
    public function __invoke(PohonMenu $pohon): JsonResponse
    {
        // Dipakai dua halaman: cukup salah satu izin lihat (AccessEvaluator lewat Gate).
        abort_unless(Gate::any(['pengaturan.peran.lihat', 'pengaturan.akun.lihat']), 403);

        return new JsonResponse(['data' => $pohon->semua()]);
    }
}
