<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\MasterData\Application\KelolaDaftarPeriksaPulang;
use App\Modules\MasterData\Models\DaftarPeriksaPulang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/v1/data-induk/daftar-periksa-pulang dan PATCH /api/v1/data-induk/daftar-periksa-pulang/{id} (kontrak v0.39.0,
 * tiket 2.40). Izin master_data.daftar_periksa_pulang.* cakupan `rs`, diperiksa lewat Gate → AccessEvaluator sebelum isian
 * dibaca (pola area IGD).
 */
final class DaftarPeriksaPulangController
{
    public function index(Request $request): JsonResponse
    {
        $this->izin('lihat');
        $filter = $request->validate(['status' => ['nullable', Rule::in(['aktif', 'nonaktif'])]]);
        $query = DaftarPeriksaPulang::query()->when($filter['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        return Paginasi::respons($query, $request, 'kode', arah: 'asc',
            bentuk: fn ($baris) => $baris->map(fn (DaftarPeriksaPulang $d) => self::bentuk($d))->values()->all());
    }

    /** Id dari rute dicari sesudah izin: akun tanpa izin selalu 403, juga untuk id tak dikenal. */
    public function update(Request $request, string $butir, KelolaDaftarPeriksaPulang $kelola): JsonResponse
    {
        $this->izin('perbaiki');
        $data = DaftarPeriksaPulang::query()->find($butir) ?? throw new ApiError(404, 'tidak_ditemukan', 'Butir daftar periksa tidak ditemukan.');
        $isi = $request->validate([
            'nama' => ['sometimes', 'string', 'min:2', 'max:150'],
            'wajib' => ['sometimes', 'boolean'],
            'urutan' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['dasar_perubahan' => 'Isi minimal satu perubahan.']);
        }
        $data = $kelola->ubah($request->user(), $data, IfMatch::versi($request), $isi)->refresh();

        return (new JsonResponse(['data' => self::bentuk($data)]))->setEtag((string) $data->revisi)->setPrivate();
    }

    /** @return array<string, mixed> */
    public static function bentuk(DaftarPeriksaPulang $d): array
    {
        return [
            'id' => $d->id, 'kode' => $d->kode, 'nama' => $d->nama, 'sumber' => $d->sumber, 'wajib' => $d->wajib, 'urutan' => $d->urutan,
            'status' => $d->status, 'versi' => (string) $d->revisi, 'diubah_pada' => $d->diubah_pada->toIso8601String(),
        ];
    }

    private function izin(string $aksi): void
    {
        Gate::authorize("master_data.daftar_periksa_pulang.$aksi", Cakupan::rs());
    }
}
