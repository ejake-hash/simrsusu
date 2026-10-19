<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\MasterData\Application\KelolaPenjamin;
use App\Modules\MasterData\Models\Penjamin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** /api/v1/data-induk/penjamin (kontrak v0.17.0, tiket 2.1). Izin master_data.penjamin.* cakupan `rs`, diperiksa lewat Gate → AccessEvaluator. */
final class PenjaminController
{
    private const PLAFON = ['nullable', 'string', 'regex:/^[0-9]{1,15}(\.[0-9]{1,2})?$/'];

    public function index(Request $request): JsonResponse
    {
        $this->izin('lihat');
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
            'jenis' => ['nullable', Rule::in(Penjamin::JENIS)],
        ]);
        $cari = isset($filter['cari']) ? '%'.addcslashes($filter['cari'], '\\%_').'%' : null;
        $query = Penjamin::query()
            ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filter['jenis'] ?? null, fn ($q, $jenis) => $q->where('jenis', $jenis))
            ->when($cari, fn ($q) => $q->where(fn ($w) => $w->where('kode', 'ilike', $cari)->orWhere('nama', 'ilike', $cari)));

        return Paginasi::respons($query, $request, 'kode', arah: 'asc',
            bentuk: fn ($baris) => $baris->map(fn (Penjamin $p) => self::bentuk($p))->values()->all());
    }

    public function show(Penjamin $penjamin): JsonResponse
    {
        $this->izin('lihat');

        return $this->satu($penjamin);
    }

    public function store(Request $request, KelolaPenjamin $kelola): JsonResponse
    {
        $this->izin('input');
        $isi = $request->validate([
            'kode' => ['required', ...IsianOrganisasi::KODE_DATA],
            'nama' => ['required', 'string', 'min:2', 'max:150'],
            'jenis' => ['required', Rule::in(Penjamin::JENIS)],
            'masa_berlaku_gl_hari' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'plafon' => self::PLAFON,
            'jatuh_tempo_hari' => ['nullable', 'integer', 'min:0', 'max:365'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);

        return $this->satu($kelola->buat($request->user(), $isi), 201);
    }

    public function update(Request $request, Penjamin $penjamin, KelolaPenjamin $kelola): JsonResponse
    {
        $this->izin('perbaiki');
        $isi = $request->validate([
            'kode' => ['sometimes', ...IsianOrganisasi::KODE_DATA],
            'nama' => ['sometimes', 'string', 'min:2', 'max:150'],
            'jenis' => ['sometimes', Rule::in(Penjamin::JENIS)],
            'masa_berlaku_gl_hari' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'plafon' => ['sometimes', ...self::PLAFON],
            'jatuh_tempo_hari' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['dasar_perubahan' => 'Isi minimal satu perubahan.']);
        }

        return $this->satu($kelola->ubah($request->user(), $penjamin, IfMatch::versi($request), $isi));
    }

    private function izin(string $aksi): void
    {
        abort_unless(Gate::allows("master_data.penjamin.$aksi", Cakupan::rs()), 403);
    }

    /** @return array<string, mixed> */
    public static function bentuk(Penjamin $p): array
    {
        return [
            'id' => $p->id, 'kode' => $p->kode, 'nama' => $p->nama, 'jenis' => $p->jenis, 'status' => $p->status,
            'masa_berlaku_gl_hari' => $p->masa_berlaku_gl_hari, 'plafon' => $p->plafon, 'jatuh_tempo_hari' => $p->jatuh_tempo_hari,
            'versi' => (string) $p->revisi, 'diubah_pada' => $p->diubah_pada->toIso8601String(),
        ];
    }

    private function satu(Penjamin $penjamin, int $status = 200): JsonResponse
    {
        $penjamin->refresh();

        return (new JsonResponse(['data' => self::bentuk($penjamin)], $status))->setEtag((string) $penjamin->revisi)->setPrivate();
    }
}
