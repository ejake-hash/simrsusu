<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Database\Like;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\MasterData\Application\KelolaRawatInap;
use App\Modules\MasterData\Http\Resources\LokasiResource;
use App\Modules\MasterData\Models\Bangsal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/v1/bangsal (kontrak v0.5.0, tiket 1.8b). */
final class BangsalController
{
    use IzinOrganisasi;

    public function __construct(private readonly LokasiResource $resource) {}

    public function index(Request $request): JsonResponse
    {
        $this->izin('lihat');
        $filter = $request->validate(['cari' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])]]);
        $query = Bangsal::query()
            ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filter['cari'] ?? null, fn ($q, $cari) => $q->where(fn ($q) => $q
                ->whereRaw('kode ILIKE ?', [Like::memuat($cari)])->orWhereRaw('nama ILIKE ?', [Like::memuat($cari)])));

        return Paginasi::respons($query, $request, 'kode', arah: 'asc', bentuk: fn ($baris) => $this->resource->bangsal($baris));
    }

    public function show(Bangsal $bangsal): JsonResponse
    {
        $this->izin('lihat');

        return $this->satu($bangsal);
    }

    public function store(Request $request, KelolaRawatInap $kelola): JsonResponse
    {
        $this->izin('input');
        $isi = $request->validate([
            'kode' => ['required', ...IsianOrganisasi::KODE_DATA],
            'nama' => ['required', 'string', 'min:2', 'max:150'],
            'unit_organisasi_id' => ['required', 'uuid'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);

        return $this->satu($kelola->buatBangsal($request->user(), $isi), 201);
    }

    public function update(Request $request, Bangsal $bangsal, KelolaRawatInap $kelola): JsonResponse
    {
        $this->izin('perbaiki');
        $isi = $request->validate([
            'kode' => ['sometimes', ...IsianOrganisasi::KODE_DATA],
            'nama' => ['sometimes', 'string', 'min:2', 'max:150'],
            'unit_organisasi_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        $this->minimalSatuPerubahan($isi);

        return $this->satu($kelola->ubahBangsal($request->user(), $bangsal, IfMatch::versi($request), $isi));
    }

    private function satu(Bangsal $bangsal, int $status = 200): JsonResponse
    {
        $bangsal->refresh();

        return $this->respons($this->resource->bangsal(collect([$bangsal]))[0], $bangsal->revisi, $status);
    }
}
