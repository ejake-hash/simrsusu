<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\MasterData\Application\KelolaInstrumenTriase;
use App\Modules\MasterData\Models\InstrumenTriase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * /api/v1/data-induk/instrumen-triase (kontrak v0.32.0, tiket 2.24). Izin master_data.instrumen_triase.* cakupan `rs`,
 * diperiksa lewat Gate → AccessEvaluator sebelum isian dibaca (pola penjamin 2.1).
 */
final class InstrumenTriaseController
{
    /** Paling banyak 20 tingkat skala per instrumen; kode skala unik di instrumen itu. */
    private const SKALA = [
        'skala' => ['array', 'max:20'],
        'skala.*' => ['array:kode,label'],
        'skala.*.kode' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,19}$/', 'distinct'],
        'skala.*.label' => ['required', 'string', 'min:1', 'max:100'],
    ];

    public function index(Request $request): JsonResponse
    {
        $this->izin('lihat');
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
        ]);
        $cari = isset($filter['cari']) ? '%'.addcslashes($filter['cari'], '\\%_').'%' : null;
        $query = InstrumenTriase::query()
            ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($cari, fn ($q) => $q->where(fn ($w) => $w->where('kode', 'ilike', $cari)->orWhere('nama', 'ilike', $cari)));

        return Paginasi::respons($query, $request, 'kode', arah: 'asc',
            bentuk: fn ($baris) => $baris->map(fn (InstrumenTriase $i) => self::bentuk($i))->values()->all());
    }

    /** Id dari rute dicari sesudah izin (bukan route-model binding): akun tanpa izin selalu 403, juga untuk id tak dikenal. */
    public function show(string $instrumen): JsonResponse
    {
        $this->izin('lihat');

        return $this->satu($this->cari($instrumen));
    }

    public function store(Request $request, KelolaInstrumenTriase $kelola): JsonResponse
    {
        $this->izin('input');
        $isi = $request->validate([
            'kode' => ['required', ...IsianOrganisasi::KODE_DATA],
            'nama' => ['required', 'string', 'min:2', 'max:150'],
            ...self::SKALA,
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);

        return $this->satu($kelola->buat($request->user(), $isi), 201);
    }

    public function update(Request $request, string $instrumen, KelolaInstrumenTriase $kelola): JsonResponse
    {
        $this->izin('perbaiki');
        $data = $this->cari($instrumen);
        $isi = $request->validate([
            'kode' => ['sometimes', ...IsianOrganisasi::KODE_DATA],
            'nama' => ['sometimes', 'string', 'min:2', 'max:150'],
            ...self::SKALA,
            'skala' => ['sometimes', 'array', 'max:20'],
            'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['dasar_perubahan' => 'Isi minimal satu perubahan.']);
        }

        return $this->satu($kelola->ubah($request->user(), $data, IfMatch::versi($request), $isi));
    }

    /** Rute sudah whereUuid, jadi id selalu berbentuk UUID di sini. */
    private function cari(string $id): InstrumenTriase
    {
        return InstrumenTriase::query()->find($id) ?? throw new ApiError(404, 'tidak_ditemukan', 'Instrumen triase tidak ditemukan.');
    }

    private function izin(string $aksi): void
    {
        Gate::authorize("master_data.instrumen_triase.$aksi", Cakupan::rs());
    }

    /** @return array<string, mixed> */
    public static function bentuk(InstrumenTriase $i): array
    {
        return [
            'id' => $i->id, 'kode' => $i->kode, 'nama' => $i->nama, 'skala' => $i->skala, 'status' => $i->status,
            'versi' => (string) $i->revisi, 'diubah_pada' => $i->diubah_pada->toIso8601String(),
        ];
    }

    private function satu(InstrumenTriase $instrumen, int $status = 200): JsonResponse
    {
        $instrumen->refresh();

        return (new JsonResponse(['data' => self::bentuk($instrumen)], $status))->setEtag((string) $instrumen->revisi)->setPrivate();
    }
}
