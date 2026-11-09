<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\MasterData\Application\KelolaDataIndukPapanIgd;
use App\Modules\MasterData\Models\AreaIgd;
use App\Modules\MasterData\Models\AturanPapanIgd;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * /api/v1/data-induk/area-igd dan /api/v1/data-induk/aturan-papan-igd (kontrak v0.34.0, tiket 2.25). Izin
 * master_data.papan_igd.* cakupan `rs`, diperiksa lewat Gate → AccessEvaluator sebelum isian dibaca (pola instrumen triase).
 */
final class PapanIgdDataIndukController
{
    public function indexArea(Request $request): JsonResponse
    {
        $this->izin('lihat');
        $filter = $request->validate(['status' => ['nullable', Rule::in(['aktif', 'nonaktif'])]]);
        $query = AreaIgd::query()->when($filter['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        return Paginasi::respons($query, $request, 'kode', arah: 'asc',
            bentuk: fn ($baris) => $baris->map(fn (AreaIgd $a) => self::bentukArea($a))->values()->all());
    }

    public function storeArea(Request $request, KelolaDataIndukPapanIgd $kelola): JsonResponse
    {
        $this->izin('input');
        $isi = $request->validate([
            'kode' => ['required', ...IsianOrganisasi::KODE_DATA],
            'nama' => ['required', 'string', 'min:2', 'max:100'],
            'urutan' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);

        return $this->satuArea($kelola->buatArea($request->user(), $isi), 201);
    }

    /** Id dari rute dicari sesudah izin (bukan route-model binding): akun tanpa izin selalu 403, juga untuk id tak dikenal. */
    public function updateArea(Request $request, string $area, KelolaDataIndukPapanIgd $kelola): JsonResponse
    {
        $this->izin('perbaiki');
        $data = AreaIgd::query()->find($area) ?? throw new ApiError(404, 'tidak_ditemukan', 'Area IGD tidak ditemukan.');
        $isi = $request->validate([
            'kode' => ['sometimes', ...IsianOrganisasi::KODE_DATA],
            'nama' => ['sometimes', 'string', 'min:2', 'max:100'],
            'urutan' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        self::minimalSatuPerubahan($isi);

        return $this->satuArea($kelola->ubahArea($request->user(), $data, IfMatch::versi($request), $isi));
    }

    public function showAturan(): JsonResponse
    {
        $this->izin('lihat');

        return $this->satuAturan(AturanPapanIgd::berlaku());
    }

    public function updateAturan(Request $request, KelolaDataIndukPapanIgd $kelola): JsonResponse
    {
        $this->izin('perbaiki');
        $isi = $request->validate([
            'batas_tanggap_menit' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'peringatan_lama_igd_menit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:359'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        self::minimalSatuPerubahan($isi);

        return $this->satuAturan($kelola->ubahAturan($request->user(), IfMatch::versi($request), $isi));
    }

    /** @return array<string, mixed> */
    public static function bentukArea(AreaIgd $a): array
    {
        return [
            'id' => $a->id, 'kode' => $a->kode, 'nama' => $a->nama, 'urutan' => $a->urutan, 'status' => $a->status,
            'versi' => (string) $a->revisi, 'diubah_pada' => $a->diubah_pada->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function bentukAturan(AturanPapanIgd $a): array
    {
        return [
            'batas_tanggap_menit' => $a->batas_tanggap_menit, 'peringatan_lama_igd_menit' => $a->peringatan_lama_igd_menit,
            'versi' => (string) $a->revisi,
            'diubah_pada' => $a->diubah_pada->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $isi */
    private static function minimalSatuPerubahan(array $isi): void
    {
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['dasar_perubahan' => 'Isi minimal satu perubahan.']);
        }
    }

    private function izin(string $aksi): void
    {
        Gate::authorize("master_data.papan_igd.$aksi", Cakupan::rs());
    }

    private function satuArea(AreaIgd $area, int $status = 200): JsonResponse
    {
        $area->refresh();

        return (new JsonResponse(['data' => self::bentukArea($area)], $status))->setEtag((string) $area->revisi)->setPrivate();
    }

    private function satuAturan(AturanPapanIgd $aturan): JsonResponse
    {
        $aturan->refresh();

        return (new JsonResponse(['data' => self::bentukAturan($aturan)]))->setEtag((string) $aturan->revisi)->setPrivate();
    }
}
