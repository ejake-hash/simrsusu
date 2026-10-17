<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Http\Paginasi;
use App\Modules\Akses\Application\KelolaPerangkat;
use App\Modules\Akses\Application\PenjagaPenyunting;
use App\Modules\Akses\Http\Resources\PerangkatPresenter;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PerangkatTepercaya;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Perangkat tepercaya (tiket 1.13): milik sendiri di menu akun, milik akun lain di Pengaturan → Akun & akses. */
final class PerangkatController
{
    public function __construct(
        private readonly KelolaPerangkat $kelola,
        private readonly PerangkatPresenter $presenter,
        private readonly PenjagaPenyunting $penjaga,
    ) {}

    public function saya(Request $request): JsonResponse
    {
        /** @var Pengguna $saya */
        $saya = $request->user();

        return $this->daftar($request, $saya, (string) $request->session()->get(KelolaPerangkat::SESI));
    }

    public function cabutSaya(Request $request, string $perangkatId): JsonResponse
    {
        /** @var Pengguna $saya */
        $saya = $request->user();
        $perangkat = PerangkatTepercaya::query()->where('pengguna_id', $saya->id)->findOrFail($perangkatId);
        $perangkat = $this->kelola->cabut($perangkat, $saya, 'dicabut_pemilik', $saya->id);
        $sesi = $request->session()->get(KelolaPerangkat::SESI);

        $respons = new JsonResponse(['data' => $this->presenter->satu($perangkat, $saya, is_string($sesi) ? $sesi : null)]);

        // Perangkat sesi ini: cookie token yang sudah tidak berguna ikut dihapus.
        return $sesi === $perangkat->id ? $respons->withCookie($this->kelola->cookieHapus()) : $respons;
    }

    public function akun(Request $request, Pengguna $akun): JsonResponse
    {
        return $this->daftar($request, $akun, null);
    }

    public function cabutAkun(Request $request, Pengguna $akun, string $perangkatId): JsonResponse
    {
        $isi = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:500']]);
        $this->penjaga->pastikanBolehMengubahAkun($request->user(), $akun);
        $perangkat = PerangkatTepercaya::query()->where('pengguna_id', $akun->id)->findOrFail($perangkatId);

        $perangkat = $this->kelola->cabut($perangkat, $akun, 'dicabut_admin', $request->user()->id, $isi['alasan']);

        return new JsonResponse(['data' => $this->presenter->satu($perangkat, $akun, null)]);
    }

    private function daftar(Request $request, Pengguna $pemilik, ?string $perangkatSesi): JsonResponse
    {
        $request->validate(['status' => ['sometimes', Rule::in(['aktif', 'semua'])]]);
        $query = PerangkatTepercaya::query()->where('pengguna_id', $pemilik->id)
            // Bawaan hanya yang aktif. Batas tersimpan disaring di query; kebijakan yang turun sesudahnya disaring per halaman
            // (halaman bisa lebih pendek dari batas, kursor tetap benar).
            ->when($request->query('status', 'aktif') === 'aktif', fn (Builder $q) => $q->whereNull('dicabut_pada')
                ->where('berlaku_sampai', '>', now()->format('Y-m-d H:i:s.uP')));

        // Masa percaya efektif sama untuk semua perangkat pemilik: dihitung sekali (hindari N+1 di filter dan presenter).
        $hariEfektif = $this->kelola->hariEfektif($pemilik);

        return Paginasi::respons($query, $request, 'dibuat_pada', bentuk: fn ($baris) => $this->presenter->daftar(
            $request->query('status', 'aktif') === 'aktif'
                ? $baris->filter(fn (PerangkatTepercaya $p) => $this->kelola->status($p, $pemilik, $hariEfektif) === 'aktif')->values()
                : $baris,
            $pemilik, $perangkatSesi !== '' ? $perangkatSesi : null,
        ));
    }
}
