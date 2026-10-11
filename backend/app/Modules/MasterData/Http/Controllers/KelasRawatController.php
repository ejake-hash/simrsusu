<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\MasterData\Application\KelolaRawatInap;
use App\Modules\MasterData\Http\Resources\LokasiResource;
use App\Modules\MasterData\Models\KelasRawat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/v1/kelas-rawat (kontrak v0.5.0, tiket 1.8b). Tanpa data bawaan: kelas dan kode Aplicares diisi RS. */
final class KelasRawatController
{
    use IzinOrganisasi;

    public function index(Request $request): JsonResponse
    {
        $this->izin('lihat');
        $filter = $request->validate(['status' => ['nullable', Rule::in(['aktif', 'nonaktif'])]]);
        $query = KelasRawat::query()->when($filter['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        return Paginasi::respons($query, $request, 'kode', arah: 'asc',
            bentuk: fn ($baris) => $baris->map(fn (KelasRawat $k) => LokasiResource::kelas($k))->values()->all());
    }

    public function show(KelasRawat $kelasRawat): JsonResponse
    {
        $this->izin('lihat');

        return $this->satu($kelasRawat);
    }

    public function store(Request $request, KelolaRawatInap $kelola): JsonResponse
    {
        $this->izin('input');
        $isi = $request->validate([
            'kode' => ['required', ...IsianOrganisasi::KODE_DATA],
            'nama' => ['required', 'string', 'min:1', 'max:100'],
            'kode_aplicares' => ['nullable', 'string', 'max:20'],
            'berlaku_dari' => ['nullable', 'date_format:Y-m-d'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);

        return $this->satu($kelola->buatKelas($request->user(), $isi), 201);
    }

    public function update(Request $request, KelasRawat $kelasRawat, KelolaRawatInap $kelola): JsonResponse
    {
        $this->izin('perbaiki');
        $isi = $request->validate([
            'kode' => ['sometimes', ...IsianOrganisasi::KODE_DATA],
            'nama' => ['sometimes', 'string', 'min:1', 'max:100'],
            'kode_aplicares' => ['sometimes', 'nullable', 'string', 'max:20'],
            'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        $this->minimalSatuPerubahan($isi);

        return $this->satu($kelola->ubahKelas($request->user(), $kelasRawat, IfMatch::versi($request), $isi));
    }

    private function satu(KelasRawat $kelas, int $status = 200): JsonResponse
    {
        $kelas->refresh();

        return $this->respons(LokasiResource::kelas($kelas), $kelas->revisi, $status);
    }
}
