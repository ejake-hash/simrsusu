<?php

namespace App\Modules\Pendaftaran\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\Pendaftaran\Application\KelolaTriase;
use App\Modules\Pendaftaran\Http\Resources\TriaseResource;
use App\Modules\Pendaftaran\Models\Kunjungan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Triase IGD (kontrak v0.32.0, tiket 2.24; FR-03-02): /kunjungan/{id}/triase dan /instrumen-triase. Izin
 * igd.triase.{lihat,input} cakupan rs diperiksa middleware rute (can.rs) sebelum isian dibaca. Respons `no-store`.
 */
final class TriaseController
{
    public function __construct(private readonly TriaseResource $resource) {}

    /** Riwayat triase satu kunjungan, terbaru dulu; baris terbaru bertanda `berlaku`. */
    public function index(Request $request, string $kunjungan): JsonResponse
    {
        $k = Kunjungan::query()->find($kunjungan, ['id', 'tiba_pada']) ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
        $tiba = $k->tiba_pada?->toIso8601String();
        $berlaku = KelolaTriase::berlaku([$k->id])[$k->id]->id ?? null;
        $query = DB::table('kunjungan.triase')->where('kunjungan_id', $k->id);

        return Paginasi::respons($query, $request, 'ditriase_pada', bentuk: fn ($baris) => $this->resource->banyak($baris, $tiba, $berlaku))
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $kunjungan, KelolaTriase $kelola): JsonResponse
    {
        $isi = $request->validate([
            'kategori' => ['required', Rule::in(KelolaTriase::KATEGORI)],
            'instrumen_triase_id' => ['nullable', 'uuid'],
            'skala' => ['nullable', 'string', 'max:50'],
            'keluhan' => ['nullable', 'string', 'max:1000'],
        ]);
        $id = $kelola->catat($request->user(), $kunjungan, $isi);
        $k = Kunjungan::query()->findOrFail($kunjungan, ['id', 'tiba_pada']);
        $baris = DB::table('kunjungan.triase')->where('id', $id)->get();

        return (new JsonResponse(['data' => $this->resource->banyak($baris, $k->tiba_pada?->toIso8601String(), $id)[0]], 201))
            ->header('Cache-Control', 'no-store');
    }

    /** Instrumen triase aktif dari data induk RS, urut kode. Daftar pendek: paling banyak 200 baris. */
    public function instrumen(): JsonResponse
    {
        $data = DB::table('inti.instrumen_triase')->where('status', 'aktif')->orderBy('kode')->limit(200)->get(['id', 'kode', 'nama', 'skala'])
            ->map(fn ($i) => ['id' => $i->id, 'kode' => $i->kode, 'nama' => $i->nama, 'skala' => KelolaTriase::daftarSkala($i->skala)])->values()->all();

        return (new JsonResponse(['data' => $data]))->header('Cache-Control', 'no-store');
    }
}
