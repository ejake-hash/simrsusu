<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Modules\Akses\Http\Resources\PenggunaResource;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/saya (kontrak Saya). Respons masuk (PRD 10.3.2) memakai PenggunaResource tanpa field khusus /saya. */
final class SayaController
{
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => self::bentuk($request, $request->user())]);
    }

    /** @return array<string, mixed> */
    public static function bentuk(Request $request, Pengguna $pengguna): array
    {
        return [...(new PenggunaResource($pengguna))->resolve($request), 'wajib_ganti_sandi' => $pengguna->wajib_ganti_sandi];
    }
}
