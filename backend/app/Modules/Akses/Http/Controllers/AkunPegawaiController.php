<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Modules\Akses\Application\AkunPegawai;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Http\Resources\AkunPresenter;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/pegawai/{id}/akun (kontrak v0.6.0, tiket 1.8d; K5): akun dari pegawai, sandi awal sekali tampil.
 * Izin pengaturan.akun.input (rute) dan sdm.pegawai.lihat.
 */
final class AkunPegawaiController
{
    public function __invoke(Request $request, Pegawai $pegawai, AkunPegawai $layanan, AkunPresenter $presenter): JsonResponse
    {
        abort_unless(Gate::allows('sdm.pegawai.lihat', Cakupan::rs()), 403);
        $isi = $request->validate([
            'nama_pengguna' => ['required', 'string', 'regex:/^[a-z][a-z0-9._-]{2,49}$/'],
            'email' => ['nullable', 'email', 'max:200'],
            'dasar_pemberian' => ['required', 'string', 'min:3', 'max:200'],
        ]);
        [$akun, $sandi] = $layanan->buat($request->user(), $pegawai->id, $isi['nama_pengguna'], $isi['email'] ?? null, $isi['dasar_pemberian']);

        return (new JsonResponse(['data' => ['akun' => $presenter->ringkas(collect([$akun->refresh()]))[0], 'sandi_awal' => $sandi]], 201))
            ->header('Cache-Control', 'no-store');
    }
}
