<?php

namespace App\Modules\Antrean\Http\Controllers;

use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Antrean\Application\KelolaAntrean;
use App\Modules\Antrean\Http\Resources\AntreanResource;
use App\Modules\Antrean\Models\Antrean;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * /api/v1/antrean (tiket 2.8). Izin pendaftaran.antrean.{lihat,perbaiki} cakupan rs, diperiksa sebelum mencari objek:
 * tanpa izin, ada atau tidaknya id tidak terbaca dari 403/404. Respons `no-store`.
 */
final class AntreanController
{
    public function __construct(private readonly AntreanResource $resource, private readonly KelolaAntrean $kelola) {}

    public function index(Request $request): JsonResponse
    {
        self::izin('pendaftaran.antrean.lihat');
        $filter = $request->validate([
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'poli_id' => ['required', 'uuid'],
            'pegawai_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(Antrean::STATUS)],
        ]);
        $query = Antrean::query()->where('poli_id', $filter['poli_id'])->where('tanggal', $filter['tanggal'])
            ->when($filter['pegawai_id'] ?? null, fn ($q, $id) => $q->where('pegawai_id', $id))
            ->when($filter['status'] ?? null, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '<>', 'batal'));

        return Paginasi::respons($query, $request, 'urutan', arah: 'asc', bentuk: fn ($baris) => $this->resource->banyak($baris))
            ->header('Cache-Control', 'no-store');
    }

    public function panggil(Request $request, string $antrean): JsonResponse
    {
        self::izin('pendaftaran.antrean.perbaiki');
        $isi = $request->validate(['lokasi_id' => ['nullable', 'uuid']]);

        return $this->satu($this->kelola->panggil($request->user(), $antrean, $isi['lokasi_id'] ?? null));
    }

    public function lewati(Request $request, string $antrean): JsonResponse
    {
        self::izin('pendaftaran.antrean.perbaiki');

        return $this->satu($this->kelola->lewati($request->user(), $antrean));
    }

    public function sisip(Request $request, string $antrean): JsonResponse
    {
        self::izin('pendaftaran.antrean.perbaiki');

        return $this->satu($this->kelola->sisip($request->user(), $antrean));
    }

    public function batal(Request $request, string $antrean): JsonResponse
    {
        self::izin('pendaftaran.antrean.perbaiki');
        $isi = $request->validate(['alasan_batal' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->satu($this->kelola->batal($request->user(), $antrean, $isi['alasan_batal']));
    }

    private static function izin(string $kode): void
    {
        Gate::authorize($kode, Cakupan::rs());
    }

    private function satu(Antrean $antrean): JsonResponse
    {
        return (new JsonResponse(['data' => $this->resource->satu($antrean->refresh())]))->header('Cache-Control', 'no-store');
    }
}
