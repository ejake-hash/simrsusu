<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Modules\MasterData\Application\ImporLokasi;
use App\Modules\MasterData\Application\PenjagaUsulan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** POST /api/v1/lokasi/impor (kontrak v0.5.0, tiket 1.8b): CSV paling besar 2 MB / 5.000 baris, sinkron. */
final class ImporLokasiController
{
    use IzinOrganisasi;

    public function __invoke(Request $request, ImporLokasi $impor): JsonResponse
    {
        $this->izin('input');
        app(PenjagaUsulan::class)->pastikanBolehLangsung();
        $isi = $request->validate([
            'berkas' => ['required', 'file', 'max:2048'],
            'mode' => ['required', Rule::in(['pratinjau', 'terapkan'])],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        $teks = (string) file_get_contents($isi['berkas']->getRealPath());

        return new JsonResponse(['data' => $isi['mode'] === 'pratinjau'
            ? $impor->pratinjau($teks)
            : $impor->terapkan($request->user(), $teks, $isi['dasar_perubahan'])]);
    }
}
