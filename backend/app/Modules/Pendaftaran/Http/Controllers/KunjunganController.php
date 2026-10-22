<?php

namespace App\Modules\Pendaftaran\Http\Controllers;

use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Pendaftaran\Application\KelolaKunjungan;
use App\Modules\Pendaftaran\Http\Resources\KunjunganResource;
use App\Modules\Pendaftaran\Models\Kunjungan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * /api/v1/kunjungan (kontrak v0.20.0, tiket 2.6). Izin pendaftaran.pasien.{lihat,input,batal} cakupan rs, diperiksa
 * sebelum mencari objek: tanpa izin, ada atau tidaknya id tidak terbaca dari 403/404. Respons `no-store`.
 */
final class KunjunganController
{
    public function __construct(private readonly KunjunganResource $resource) {}

    public function index(Request $request): JsonResponse
    {
        self::izin('pendaftaran.pasien.lihat');
        $filter = $request->validate([
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'poli_id' => ['required', 'uuid'],
            'pegawai_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(Kunjungan::STATUS)],
        ]);
        $status = $filter['status'] ?? null;
        $query = Kunjungan::query()->where('poli_id', $filter['poli_id'])->where('tanggal', $filter['tanggal'])
            ->when($filter['pegawai_id'] ?? null, fn ($q, $id) => $q->where('pegawai_id', $id))
            ->when($status, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '<>', 'batal'));

        return Paginasi::respons($query, $request, 'dibuat_pada', arah: 'asc', bentuk: fn ($baris) => $this->resource->banyak($baris))
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, KelolaKunjungan $kelola): JsonResponse
    {
        self::izin('pendaftaran.pasien.input');
        $isi = $request->validate([
            'pasien_id' => ['required', 'uuid'],
            'jadwal_praktik_id' => ['required', 'uuid'],
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'jenis_kunjungan' => ['nullable', 'string', 'max:50'],
            'cara_bayar' => ['required', Rule::in(['jkn', 'non_jkn'])],
            'penjamin_id' => ['nullable', 'uuid'],
            'keluhan' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->satu($kelola->daftar($request->user(), $isi), 201);
    }

    public function batal(Request $request, string $kunjungan, KelolaKunjungan $kelola): JsonResponse
    {
        self::izin('pendaftaran.pasien.batal');
        $isi = $request->validate(['alasan_batal' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->satu($kelola->batal($request->user(), $kunjungan, $isi['alasan_batal']));
    }

    private static function izin(string $kode): void
    {
        abort_unless(Gate::allows($kode, Cakupan::rs()), 403);
    }

    private function satu(Kunjungan $kunjungan, int $status = 200): JsonResponse
    {
        $kunjungan->refresh();
        $respons = new JsonResponse(['data' => $this->resource->satu($kunjungan)], $status);

        return $respons->setEtag((string) $kunjungan->revisi)->header('Cache-Control', 'no-store');
    }
}
