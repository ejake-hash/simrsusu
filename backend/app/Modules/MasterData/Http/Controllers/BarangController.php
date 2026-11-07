<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\MasterData\Application\ImporKatalogObat;
use App\Modules\MasterData\Application\KelolaBarang;
use App\Modules\MasterData\Models\Barang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** /api/v1/data-induk/barang (kontrak v0.23.0, tiket 2.11). Izin farmasi.formularium.* cakupan `rs`. */
final class BarangController
{
    public function index(Request $request): JsonResponse
    {
        self::izin('lihat');
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'jenis' => ['nullable', Rule::in(Barang::JENIS)],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
            'tanpa_kfa' => ['nullable', 'boolean'],
        ]);
        $query = Barang::query()
            ->when($filter['jenis'] ?? null, fn ($q, $jenis) => $q->where('jenis', $jenis))
            ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('tanpa_kfa'), fn ($q) => $q->where('status', 'aktif')->whereNull('kode_kfa'))
            // Ekspresi sama dengan indeks trigram barang_cari_trgm.
            ->when($filter['cari'] ?? null, fn ($q, $cari) => $q->whereRaw("(kode || ' ' || nama || ' ' || coalesce(zat_aktif, '')) ilike ?", ['%'.addcslashes($cari, '\\%_').'%']));

        return Paginasi::respons($query, $request, 'nama', arah: 'asc',
            bentuk: fn ($baris) => $baris->map(fn (Barang $b) => self::bentuk($b))->values()->all());
    }

    public function show(Barang $barang): JsonResponse
    {
        self::izin('lihat');

        return $this->satu($barang);
    }

    public function store(Request $request, KelolaBarang $kelola): JsonResponse
    {
        self::izin('input');
        $isi = $request->validate([...self::aturan('required'), 'dasar_perubahan' => IsianOrganisasi::DASAR]);

        return $this->satu($kelola->buat($request->user(), $isi), 201);
    }

    public function update(Request $request, Barang $barang, KelolaBarang $kelola): JsonResponse
    {
        self::izin('perbaiki');
        $isi = $request->validate([...self::aturan('sometimes'), 'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])], 'dasar_perubahan' => IsianOrganisasi::DASAR]);
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['dasar_perubahan' => 'Isi minimal satu perubahan.']);
        }

        return $this->satu($kelola->ubah($request->user(), $barang, IfMatch::versi($request), $isi));
    }

    public function impor(Request $request, ImporKatalogObat $impor): JsonResponse
    {
        self::izin('input');
        $isi = self::validasiImpor($request);

        return new JsonResponse(['data' => $impor->barang($request->user(), $isi['mode'], (string) file_get_contents($isi['berkas']->getRealPath()), $isi['dasar_perubahan'])]);
    }

    /** @return array<string, mixed> */
    public static function validasiImpor(Request $request): array
    {
        return $request->validate([
            'berkas' => ['required', 'file', 'max:2048'],
            'mode' => ['required', Rule::in(['pratinjau', 'terapkan'])],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
    }

    public static function izin(string $aksi): void
    {
        Gate::authorize("farmasi.formularium.$aksi", Cakupan::rs());
    }

    /** @return array<string, list<mixed>> */
    private static function aturan(string $wajib): array
    {
        $opsional = $wajib === 'required' ? 'nullable' : 'sometimes';

        return [
            'kode' => [$wajib, ...IsianOrganisasi::KODE_DATA],
            'nama' => [$wajib, 'string', 'min:2', 'max:200'],
            'jenis' => [$wajib, Rule::in(Barang::JENIS)],
            'generik_paten' => [$opsional, 'nullable', Rule::in(['generik', 'paten']), ...($wajib === 'required' ? ['required_if:jenis,obat'] : [])],
            'zat_aktif' => [$opsional, 'nullable', 'string', 'max:300'],
            'kekuatan' => [$opsional, 'nullable', 'string', 'max:100'],
            'sediaan' => [$opsional, 'nullable', 'string', 'max:100'],
            'satuan_terkecil' => [$wajib, 'string', 'min:1', 'max:30'],
            'kode_kfa' => [$opsional, 'nullable', 'string', 'max:20'],
            'kewaspadaan_tinggi' => ['sometimes', 'boolean'],
            'narkotika' => ['sometimes', 'boolean'],
            'lasa' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public static function bentuk(Barang $b): array
    {
        return [
            'id' => $b->id, 'kode' => $b->kode, 'nama' => $b->nama, 'jenis' => $b->jenis, 'generik_paten' => $b->generik_paten,
            'zat_aktif' => $b->zat_aktif, 'kekuatan' => $b->kekuatan, 'sediaan' => $b->sediaan, 'satuan_terkecil' => $b->satuan_terkecil,
            'kode_kfa' => $b->kode_kfa, 'kewaspadaan_tinggi' => $b->kewaspadaan_tinggi, 'narkotika' => $b->narkotika, 'lasa' => $b->lasa,
            'status' => $b->status, 'versi' => (string) $b->revisi, 'diubah_pada' => $b->diubah_pada->toIso8601String(),
        ];
    }

    private function satu(Barang $barang, int $status = 200): JsonResponse
    {
        $barang->refresh();

        return (new JsonResponse(['data' => self::bentuk($barang)], $status))->setEtag((string) $barang->revisi)->setPrivate();
    }
}
