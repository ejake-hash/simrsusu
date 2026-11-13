<?php

namespace App\Modules\Lab\Http\Controllers;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Lab\Application\BacaDaftarKerjaLab;
use App\Modules\Lab\Application\KelolaSpesimen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Ambil sampel (penunjang.laboratorium.input) dan terima spesimen di lab (penunjang.laboratorium.verifikasi), keduanya
 * cakupan RS (tiket 2.30). Respons `Spesimen`, `no-store`.
 */
final class SpesimenController
{
    public function __construct(
        private readonly KelolaSpesimen $kelola,
        private readonly BacaDaftarKerjaLab $baca,
    ) {}

    public function ambil(Request $request, string $item): JsonResponse
    {
        Gate::authorize(BacaDaftarKerjaLab::IZIN_AMBIL, Cakupan::rs());
        $isian = $request->validate([
            'jenis' => ['required', 'string', 'min:2', 'max:100'],
            'lokasi_pengambilan' => ['required', Rule::in(['instalasi', 'samping_tt', 'kamar_operasi'])],
        ]);

        return $this->satu($this->kelola->ambil($request->user(), $item, $isian), 201);
    }

    public function terima(Request $request, string $spesimen): JsonResponse
    {
        Gate::authorize(BacaDaftarKerjaLab::IZIN_TERIMA, Cakupan::rs());

        return $this->satu($this->kelola->terima($request->user(), $spesimen));
    }

    private function satu(string $id, int $status = 200): JsonResponse
    {
        return (new JsonResponse(['data' => $this->baca->spesimen($id)], $status))
            ->setPrivate()->header('Cache-Control', 'no-store, private');
    }
}
