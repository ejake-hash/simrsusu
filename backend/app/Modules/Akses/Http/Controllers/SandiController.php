<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Modules\Akses\Application\GantiSandi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/v1/saya/sandi (kontrak v0.6.0, tiket 1.8d): ganti sandi akun yang sedang masuk. */
final class SandiController
{
    public function __invoke(Request $request, GantiSandi $ganti): JsonResponse
    {
        $isi = $request->validate(['sandi_lama' => ['required', 'string', 'max:200'], 'sandi_baru' => ['required', 'string', 'max:200']]);
        $pengguna = $ganti->ganti($request, $request->user(), $isi['sandi_lama'], $isi['sandi_baru']);

        return new JsonResponse(['data' => SayaController::bentuk($request, $pengguna)]);
    }
}
