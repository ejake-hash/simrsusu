<?php

namespace App\Modules\Pendaftaran\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\Pendaftaran\Application\KelolaDisposisi;
use App\Modules\Pendaftaran\Http\Resources\DisposisiResource;
use App\Modules\Pendaftaran\Models\Kunjungan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Disposisi IGD (kontrak v0.33.0, tiket 2.26; FR-03-09): /kunjungan/{id}/disposisi. Izin igd.disposisi.{lihat,input}
 * cakupan rs diperiksa middleware rute (can.rs) sebelum isian dibaca; izin sel matriks diperiksa KelolaDisposisi.
 * Respons `no-store`.
 */
final class DisposisiController
{
    public function __construct(private readonly DisposisiResource $resource) {}

    /** Riwayat perintah disposisi satu kunjungan, terbaru dulu. */
    public function index(Request $request, string $kunjungan): JsonResponse
    {
        $k = Kunjungan::query()->find($kunjungan, ['id']) ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
        $query = DB::table('kunjungan.perpindahan_pos')->where('dari_kunjungan_id', $k->id);

        return Paginasi::respons($query, $request, 'terjadi_pada', bentuk: fn ($baris) => $this->resource->banyak($baris))
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $kunjungan, KelolaDisposisi $kelola): JsonResponse
    {
        $isi = $request->validate([
            'tujuan' => ['required', Rule::in(KelolaDisposisi::TUJUAN)],
            'alasan' => ['required', 'string', 'min:5', 'max:1000'],
            'serah_terima' => [Rule::requiredIf(in_array($request->input('tujuan'), KelolaDisposisi::BUTUH_SERAH_TERIMA, true)), 'nullable', 'string', 'min:5', 'max:4000'],
        ]);
        $id = $kelola->catat($request->user(), $kunjungan, $isi);
        $baris = DB::table('kunjungan.perpindahan_pos')->where('id', $id)->get();

        return (new JsonResponse(['data' => $this->resource->banyak($baris)[0]], 201))->header('Cache-Control', 'no-store');
    }
}
