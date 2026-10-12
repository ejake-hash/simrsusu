<?php

namespace App\Core\Instalasi\Http;

use App\Core\Instalasi\Penyiapan;
use App\Modules\Akses\Domain\Cakupan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** GET /api/v1/penyiapan dan POST /api/v1/penyiapan/{langkah}/selesai (kontrak v0.12.0, tiket 1.8f). */
final class PenyiapanController
{
    public function __construct(private readonly Penyiapan $penyiapan) {}

    public function show(Request $request): JsonResponse
    {
        // Lapis 3: penyiapan milik seluruh RS, jadi butuh cakupan `rs`.
        abort_unless(Gate::allows('pengaturan.penyiapan.lihat', Cakupan::rs()), 403);

        return response()->json(['data' => $this->penyiapan->status($request->user())]);
    }

    public function selesai(Request $request, string $langkah): JsonResponse
    {
        abort_unless(Gate::allows('pengaturan.penyiapan.perbaiki', Cakupan::rs()), 403);
        $isi = $request->validate([
            'dilewati' => ['sometimes', 'boolean'],
            'alasan' => ['nullable', 'string', 'min:5', 'max:500', 'required_if_accepted:dilewati'],
        ]);
        $dilewati = (bool) ($isi['dilewati'] ?? false);

        return response()->json(['data' => $this->penyiapan->konfirmasi($request->user(), $langkah, $dilewati, $isi['alasan'] ?? null)]);
    }
}
