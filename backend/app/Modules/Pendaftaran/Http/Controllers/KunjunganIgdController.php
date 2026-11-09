<?php

namespace App\Modules\Pendaftaran\Http\Controllers;

use App\Core\Http\Paginasi;
use App\Modules\Pendaftaran\Application\KelolaKunjungan;
use App\Modules\Pendaftaran\Http\Resources\KunjunganResource;
use App\Modules\Pendaftaran\Models\Kunjungan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/v1/kunjungan-igd (kontrak v0.31.0, tiket 2.23). Izin igd.pendaftaran.{lihat,input} diperiksa middleware rute
 * sebelum isian dibaca. Respons `no-store`.
 */
final class KunjunganIgdController
{
    public function __construct(private readonly KunjunganResource $resource) {}

    /** Kunjungan IGD; bawaan yang masih di IGD (terdaftar/dilayani), urut jam tiba terlama dulu. */
    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(['status' => ['nullable', Rule::in(Kunjungan::STATUS)]]);
        $query = Kunjungan::query()->where('jenis_kunjungan', Kunjungan::IGD)
            ->when($filter['status'] ?? null, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->whereIn('status', Kunjungan::STATUS_AKTIF));

        return Paginasi::respons($query, $request, 'tiba_pada', arah: 'asc', bentuk: fn ($baris) => $this->resource->banyak($baris))
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, KelolaKunjungan $kelola): JsonResponse
    {
        $isi = $request->validate([
            'pasien_id' => ['required', 'uuid'],
            'cara_bayar' => ['required', Rule::in(['jkn', 'non_jkn'])],
            'penjamin_id' => ['nullable', 'uuid'],
            'keluhan' => ['nullable', 'string', 'max:1000'],
        ]);
        $kunjungan = $kelola->daftarIgd($request->user(), $isi)->refresh();
        $respons = new JsonResponse(['data' => $this->resource->satu($kunjungan)], 201);

        return $respons->setEtag((string) $kunjungan->revisi)->header('Cache-Control', 'no-store');
    }
}
