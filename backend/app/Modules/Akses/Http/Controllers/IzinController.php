<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Http\ApiError;
use App\Modules\Akses\Application\PenyetujuIzin;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Http\Resources\PersetujuanPresenter;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Peran;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Penyetuju (pemilik data) izin sensitif: PUT /izin/{kode}/penyetuju (K3/K13, tiket 1.2d). */
final class IzinController
{
    public function __construct(
        private readonly PenyetujuIzin $penyetuju,
        private readonly PersetujuanPresenter $presenter,
    ) {}

    public function aturPenyetuju(Request $request, string $kode): JsonResponse
    {
        // Pemilik data berlaku untuk seluruh RS: penyunting dengan cakupan unit saja tidak boleh mengubahnya.
        if (Gate::denies('pengaturan.peran.perbaiki', Cakupan::rs())) {
            throw new ApiError(403, 'akses_ditolak', 'Anda tidak punya izin untuk tindakan ini.');
        }
        $isi = $request->validate([
            'peran_id' => ['required', 'uuid'],
            'dasar_perubahan' => ['required', 'string', 'min:3', 'max:200'],
        ]);
        $izin = Izin::query()->where('kode', $kode)->first() ?? throw new ApiError(404, 'tidak_ditemukan', 'Data tidak ditemukan.');

        $hasil = $this->penyetuju->atur($request->user(), $izin, $isi['peran_id'], $isi['dasar_perubahan']);
        if (! $hasil instanceof Izin) {
            return new JsonResponse(['data' => $this->presenter->satu($hasil->refresh(), $request->user())], 202);
        }
        $peran = Peran::query()->find($hasil->peran_penyetuju_id);

        return new JsonResponse(['data' => [
            'kode' => $hasil->kode,
            'sensitif' => $hasil->sensitif,
            'penyetuju_peran' => $peran === null ? null : ['id' => $peran->id, 'kode' => $peran->kode, 'nama' => $peran->nama],
        ]]);
    }
}
