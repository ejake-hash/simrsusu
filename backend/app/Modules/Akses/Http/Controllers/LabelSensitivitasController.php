<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Application\KelolaLabel;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Http\Resources\LabelPresenter;
use App\Modules\Akses\Http\Resources\PersetujuanPresenter;
use App\Modules\Akses\Models\LabelSensitivitas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Label sensitivitas (data induk) dan penyetujunya (tiket 1.5, kontrak v0.14.0). */
final class LabelSensitivitasController
{
    public function __construct(
        private readonly KelolaLabel $kelola,
        private readonly LabelPresenter $presenter,
        private readonly PersetujuanPresenter $persetujuan,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return Paginasi::respons(LabelSensitivitas::query(), $request, 'dibuat_pada', arah: 'asc',
            bentuk: fn ($baris) => $this->presenter->label($baris));
    }

    public function aturPenyetuju(Request $request, string $kode): JsonResponse
    {
        // Penyetuju label berlaku untuk seluruh RS: penyunting dengan cakupan unit saja tidak boleh mengubahnya.
        Gate::authorize('pengaturan.peran.perbaiki', Cakupan::rs());
        $isi = $request->validate([
            'peran_id' => ['required', 'uuid'],
            'dasar_perubahan' => ['required', 'string', 'min:3', 'max:200'],
        ]);
        $label = LabelSensitivitas::query()->where('kode', $kode)->where('aktif', true)->first()
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Data tidak ditemukan.');

        $p = $this->kelola->aturPenyetuju($request->user(), $label, $isi['peran_id'], $isi['dasar_perubahan']);

        return new JsonResponse(['data' => $this->persetujuan->satu($p->refresh(), $request->user())], 202);
    }
}
