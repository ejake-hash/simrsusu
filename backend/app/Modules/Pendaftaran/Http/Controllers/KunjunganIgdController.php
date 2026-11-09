<?php

namespace App\Modules\Pendaftaran\Http\Controllers;

use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Pendaftaran\Application\KelolaKunjungan;
use App\Modules\Pendaftaran\Application\KelolaTriase;
use App\Modules\Pendaftaran\Http\Resources\KunjunganResource;
use App\Modules\Pendaftaran\Models\Kunjungan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * /api/v1/kunjungan-igd (kontrak v0.31.0, tiket 2.23; field `triase` v0.32.0, tiket 2.24). Izin cakupan rs diperiksa
 * sebelum isian dibaca: POST lewat middleware rute (can.rs:igd.pendaftaran.input), GET di index. Respons `no-store`.
 */
final class KunjunganIgdController
{
    public function __construct(private readonly KunjunganResource $resource) {}

    /**
     * Kunjungan IGD; bawaan yang masih di IGD (terdaftar/dilayani), urut jam tiba terlama dulu. Dipakai layar
     * Pendaftaran IGD dan Triase (2.24), jadi cukup salah satu izin lihat. Field `triase` (triase berlaku) hanya untuk
     * pemegang igd.triase.lihat: bagian tanpa izin tidak dikirim.
     */
    public function index(Request $request): JsonResponse
    {
        // Penolakan lewat authorize agar kode lapis 5 tidak tertelan (pola MenuController).
        $bolehTriase = Gate::allows('igd.triase.lihat', Cakupan::rs());
        if (! $bolehTriase) {
            Gate::authorize('igd.pendaftaran.lihat', Cakupan::rs());
        }
        $filter = $request->validate(['status' => ['nullable', Rule::in(Kunjungan::STATUS)]]);
        $query = Kunjungan::query()->where('jenis_kunjungan', Kunjungan::IGD)
            ->when($filter['status'] ?? null, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->whereIn('status', Kunjungan::STATUS_AKTIF));

        return Paginasi::respons($query, $request, 'tiba_pada', arah: 'asc', bentuk: function ($baris) use ($bolehTriase) {
            $data = $this->resource->banyak($baris);
            if (! $bolehTriase) {
                return $data;
            }
            $triase = KelolaTriase::berlaku(array_column($data, 'id'));

            return array_map(fn (array $k) => [...$k, 'triase' => isset($triase[$k['id']])
                ? ['kategori' => $triase[$k['id']]->kategori, 'ditriase_pada' => Carbon::parse($triase[$k['id']]->ditriase_pada)->toIso8601String()]
                : null], $data);
        })->header('Cache-Control', 'no-store');
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
