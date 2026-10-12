<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\MasterData\Application\PemuatReferensi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** POST /api/v1/paket-referensi/{sistem}/muat (kontrak v0.11.0, tiket 1.8e). Izin master_data.katalog.input, cakupan rs. */
final class PaketReferensiController
{
    public function muat(Request $request, PemuatReferensi $pemuat, string $sistem): JsonResponse
    {
        abort_unless(Gate::allows('master_data.katalog.input', Cakupan::rs()), 403);
        $isi = $request->validate([
            'berkas' => ['required', 'file', 'max:10240'],
            'mode' => ['required', Rule::in(['pratinjau', 'terapkan'])],
            'versi' => ['required', 'string', 'min:1', 'max:50'],
            'sumber' => ['required', 'string', 'min:3', 'max:500'],
            // LOINC: atribusi Regenstrief wajib (syarat lisensi).
            'catatan_lisensi' => [$sistem === 'loinc' ? 'required' : 'nullable', 'string', 'max:1000'],
        ]);
        $teks = (string) file_get_contents($isi['berkas']->getRealPath());

        return new JsonResponse(['data' => $pemuat->muat($sistem, $teks,
            ['versi' => $isi['versi'], 'sumber' => $isi['sumber'], 'catatan_lisensi' => $isi['catatan_lisensi'] ?? null],
            $isi['mode'] === 'terapkan', $request->user()->id)]);
    }
}
