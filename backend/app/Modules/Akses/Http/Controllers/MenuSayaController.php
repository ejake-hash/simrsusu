<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Modules\Akses\Application\MenuSaya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/saya/menu: menu efektif + izin, dengan ETag dari `versi` (304 bila tidak berubah). */
final class MenuSayaController
{
    public function __invoke(Request $request, MenuSaya $menu): JsonResponse
    {
        $data = $menu->untuk($request->user());

        $respons = new JsonResponse(['data' => $data]);
        $respons->setEtag($data['versi'])->setPrivate();
        $respons->headers->addCacheControlDirective('no-cache');
        $respons->isNotModified($request);

        return $respons;
    }
}
