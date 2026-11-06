<?php

namespace App\Core\Instalasi\Http;

use App\Core\Instalasi\Penyiapan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/penyiapan dan POST /api/v1/penyiapan/{langkah}/selesai (kontrak v0.12.0, tiket 1.8f); izin `can.rs:` di rute. */
final class PenyiapanController
{
    public function __construct(private readonly Penyiapan $penyiapan) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->penyiapan->status($request->user())]);
    }

    public function selesai(Request $request, string $langkah): JsonResponse
    {
        $isi = $request->validate([
            'dilewati' => ['sometimes', 'boolean'],
            'alasan' => ['nullable', 'string', 'min:5', 'max:500', 'required_if_accepted:dilewati'],
        ]);
        $dilewati = (bool) ($isi['dilewati'] ?? false);

        return response()->json(['data' => $this->penyiapan->konfirmasi($request->user(), $langkah, $dilewati, $isi['alasan'] ?? null)]);
    }
}
