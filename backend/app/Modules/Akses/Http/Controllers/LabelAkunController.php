<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Http\Paginasi;
use App\Modules\Akses\Application\KelolaLabel;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Http\Resources\LabelPresenter;
use App\Modules\Akses\Http\Resources\PersetujuanPresenter;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Izin label sensitivitas per akun: GET/POST /akun/{id}/label, POST .../batal (tiket 1.5, kontrak v0.14.0). */
final class LabelAkunController
{
    public function __construct(
        private readonly KelolaLabel $kelola,
        private readonly LabelPresenter $presenter,
        private readonly PersetujuanPresenter $persetujuan,
    ) {}

    public function index(Request $request, Pengguna $akun): JsonResponse
    {
        return Paginasi::respons(PenggunaLabel::query()->where('pengguna_id', $akun->id), $request, 'dibuat_pada',
            bentuk: fn ($baris) => $this->presenter->izin($baris));
    }

    public function ajukan(Request $request, Pengguna $akun): JsonResponse
    {
        // Label berlaku di seluruh RS (PRD 9.2.2): hanya penyunting bercakupan RS.
        Gate::authorize('pengaturan.akun.input', Cakupan::rs());
        $isi = $request->validate([
            'label_kode' => ['required', 'string', 'max:50'],
            'berlaku_sampai' => ['required', 'date'],
            'dasar_pemberian' => ['required', 'string', 'min:3', 'max:200'],
            'alasan' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $p = $this->kelola->ajukan($request->user(), $akun, $isi);

        return new JsonResponse(['data' => $this->persetujuan->satu($p->refresh(), $request->user())], 202);
    }

    public function batalkan(Request $request, Pengguna $akun, string $labelAkunId): JsonResponse
    {
        $isi = $request->validate(['alasan_batal' => ['required', 'string', 'min:5', 'max:500']]);
        $izin = PenggunaLabel::query()->where('pengguna_id', $akun->id)->findOrFail($labelAkunId);

        $izin = $this->kelola->batalkan($request->user(), $akun, $izin, $isi['alasan_batal']);

        return new JsonResponse(['data' => $this->presenter->izin(collect([$izin]))[0]]);
    }
}
