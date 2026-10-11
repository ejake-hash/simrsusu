<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Modules\Akses\Application\MenuSaya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/saya/menu: menu efektif + izin, ETag dari `versi` + lencana tugas (304 bila keduanya tidak berubah). */
final class MenuSayaController
{
    public function __invoke(Request $request, MenuSaya $menu): JsonResponse
    {
        $data = $menu->untuk($request->user());

        $respons = new JsonResponse(['data' => $data]);
        $jumlahTugas = array_sum(array_column(array_merge(...array_column($data['grup'], 'menu')), 'jumlah_tugas'));
        $respons->setEtag($data['versi'].'-'.$jumlahTugas)->setPrivate();
        $respons->headers->addCacheControlDirective('no-cache');
        $respons->isNotModified($request);

        return $respons;
    }
}
