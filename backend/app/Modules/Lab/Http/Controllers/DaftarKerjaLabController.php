<?php

namespace App\Modules\Lab\Http\Controllers;

use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Lab\Application\BacaDaftarKerjaLab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GET /lab/daftar-kerja (tiket 2.30; `tahap` 2.31): order lab aktif dari semua pos, satu prioritas per panggilan (layar memanggil cito
 * dulu), paling lama dulu. Izin penunjang.laboratorium.lihat cakupan RS. Respons `no-store`.
 */
final class DaftarKerjaLabController
{
    public function __construct(private readonly BacaDaftarKerjaLab $baca) {}

    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize(BacaDaftarKerjaLab::IZIN_LIHAT, Cakupan::rs());
        $isian = $request->validate([
            'prioritas' => ['required', Rule::in(['cito', 'rutin'])],
            'tahap' => ['nullable', Rule::in(array_keys(BacaDaftarKerjaLab::TAHAP))],
        ]);
        $pengguna = $request->user();

        return Paginasi::respons(BacaDaftarKerjaLab::query($isian['prioritas'], $isian['tahap'] ?? 'spesimen'), $request, 'p.dibuat_pada', 'p.id', 'asc',
            fn (Collection $baris) => $this->baca->bentuk($baris, $pengguna))
            ->setPrivate()->header('Cache-Control', 'no-store, private');
    }
}
