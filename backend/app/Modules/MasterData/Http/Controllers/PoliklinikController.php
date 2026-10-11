<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Database\Like;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\MasterData\Application\KelolaRawatInap;
use App\Modules\MasterData\Application\PohonLokasi;
use App\Modules\MasterData\Http\Resources\LokasiResource;
use App\Modules\MasterData\Models\Poliklinik;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/v1/poliklinik (kontrak v0.5.0, tiket 1.8b). Kode poli BPJS diisi RS dari referensi resmi. */
final class PoliklinikController
{
    use IzinOrganisasi;

    public function __construct(private readonly LokasiResource $resource) {}

    public function index(Request $request, PohonLokasi $pohon): JsonResponse
    {
        $this->izin('lihat');
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
            'lokasi_id' => ['nullable', 'uuid'],
        ]);
        $query = Poliklinik::query()
            ->when($filter['lokasi_id'] ?? null, fn ($q, $id) => $q->whereIn('lokasi_id', $pohon->subpohon($id)))
            ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filter['cari'] ?? null, fn ($q, $cari) => $q->where(fn ($q) => $q
                ->whereRaw('kode ILIKE ?', [Like::memuat($cari)])->orWhereRaw('nama ILIKE ?', [Like::memuat($cari)])));

        return Paginasi::respons($query, $request, 'kode', arah: 'asc', bentuk: fn ($baris) => $this->resource->poliklinik($baris));
    }

    public function show(Poliklinik $poliklinik): JsonResponse
    {
        $this->izin('lihat');

        return $this->satu($poliklinik);
    }

    public function store(Request $request, KelolaRawatInap $kelola): JsonResponse
    {
        $this->izin('input');
        $isi = $request->validate([
            'kode' => ['required', ...IsianOrganisasi::KODE_DATA],
            'nama' => ['required', 'string', 'min:2', 'max:150'],
            'unit_organisasi_id' => ['required', 'uuid'],
            'lokasi_id' => ['nullable', 'uuid'],
            'kode_poli_bpjs' => ['nullable', 'string', 'max:10'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);

        return $this->satu($kelola->buatPoliklinik($request->user(), $isi), 201);
    }

    public function update(Request $request, Poliklinik $poliklinik, KelolaRawatInap $kelola): JsonResponse
    {
        $this->izin('perbaiki');
        $isi = $request->validate([
            'kode' => ['sometimes', ...IsianOrganisasi::KODE_DATA],
            'nama' => ['sometimes', 'string', 'min:2', 'max:150'],
            'unit_organisasi_id' => ['sometimes', 'uuid'],
            'lokasi_id' => ['sometimes', 'nullable', 'uuid'],
            'kode_poli_bpjs' => ['sometimes', 'nullable', 'string', 'max:10'],
            'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        $this->minimalSatuPerubahan($isi);

        return $this->satu($kelola->ubahPoliklinik($request->user(), $poliklinik, IfMatch::versi($request), $isi));
    }

    private function satu(Poliklinik $poli, int $status = 200): JsonResponse
    {
        $poli->refresh();

        return $this->respons($this->resource->poliklinik(collect([$poli]))[0], $poli->revisi, $status);
    }
}
