<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\MasterData\Application\ImporKatalogObat;
use App\Modules\MasterData\Application\KelolaFormularium;
use App\Modules\MasterData\Models\Formularium;
use App\Modules\MasterData\Models\ItemFormularium;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** /api/v1/data-induk/formularium dan itemnya (kontrak v0.23.0, tiket 2.11). Izin farmasi.formularium.* cakupan `rs`. */
final class FormulariumController
{
    public function index(Request $request): JsonResponse
    {
        BarangController::izin('lihat');
        $filter = $request->validate([
            'jenis' => ['nullable', Rule::in(Formularium::JENIS)],
            'penjamin_id' => ['nullable', 'uuid'],
            'berlaku_pada' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $query = Formularium::query()->with('penjamin')->withCount(['item as jumlah_item_aktif' => fn ($q) => $q->where('status', 'aktif')])
            ->when($filter['jenis'] ?? null, fn ($q, $jenis) => $q->where('jenis', $jenis))
            ->when($filter['penjamin_id'] ?? null, fn ($q, $id) => $q->where('penjamin_id', $id))
            ->when($filter['berlaku_pada'] ?? null, fn ($q, $tgl) => $q->where('berlaku_dari', '<=', $tgl)
                ->where(fn ($w) => $w->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $tgl)));

        return Paginasi::respons($query, $request, 'berlaku_dari',
            bentuk: fn ($baris) => $baris->map(fn (Formularium $f) => self::bentuk($f))->values()->all());
    }

    public function show(Formularium $formularium): JsonResponse
    {
        BarangController::izin('lihat');

        return $this->satu($formularium);
    }

    public function store(Request $request, KelolaFormularium $kelola): JsonResponse
    {
        BarangController::izin('input');
        $isi = $request->validate([
            'kode' => ['required', ...IsianOrganisasi::KODE_DATA],
            'nama' => ['required', 'string', 'min:2', 'max:150'],
            'jenis' => ['required', Rule::in(Formularium::JENIS)],
            'penjamin_id' => ['nullable', 'uuid'],
            'berlaku_dari' => ['required', 'date_format:Y-m-d'],
            'berlaku_sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:berlaku_dari'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);

        return $this->satu($kelola->buat($request->user(), $isi), 201);
    }

    public function update(Request $request, Formularium $formularium, KelolaFormularium $kelola): JsonResponse
    {
        BarangController::izin('perbaiki');
        $isi = $request->validate([
            'nama' => ['sometimes', 'string', 'min:2', 'max:150'],
            'berlaku_dari' => ['sometimes', 'date_format:Y-m-d'],
            'berlaku_sampai' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['dasar_perubahan' => 'Isi minimal satu perubahan.']);
        }

        return $this->satu($kelola->ubah($request->user(), $formularium, IfMatch::versi($request), $isi));
    }

    public function item(Request $request, Formularium $formularium): JsonResponse
    {
        BarangController::izin('lihat');
        $filter = $request->validate(['cari' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])]]);
        // Urut nama barang: keyset di atas subkueri berkolom barang_nama.
        $dalam = DB::table('farmasi.formularium_item as i')->join('logistik.barang as b', 'b.id', '=', 'i.barang_id')
            ->where('i.formularium_id', $formularium->id)
            ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('i.status', $status))
            ->when($filter['cari'] ?? null, fn ($q, $cari) => $q->whereRaw("(b.kode || ' ' || b.nama || ' ' || coalesce(b.zat_aktif, '')) ilike ?", ['%'.addcslashes($cari, '\\%_').'%']))
            ->select('i.id', 'b.nama as barang_nama');
        $query = DB::query()->fromSub($dalam, 'x');

        return Paginasi::respons($query, $request, 'barang_nama', arah: 'asc', bentuk: function ($baris) {
            $item = ItemFormularium::query()->with('barang')->whereKey($baris->pluck('id'))->get()->keyBy('id');

            return $baris->map(fn ($r) => self::bentukItem($item[$r->id]))->values()->all();
        });
    }

    public function tambahItem(Request $request, Formularium $formularium, KelolaFormularium $kelola): JsonResponse
    {
        BarangController::izin('input');
        $isi = $request->validate([
            'barang_id' => ['required', 'uuid'],
            'restriksi' => ['nullable', 'string', 'max:1000'],
            'maks_per_resep' => ['nullable', 'integer', 'min:1'],
            'kronis' => ['sometimes', 'boolean'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);

        return $this->satuItem($kelola->tambahItem($request->user(), $formularium, $isi), 201);
    }

    public function ubahItem(Request $request, Formularium $formularium, ItemFormularium $item, KelolaFormularium $kelola): JsonResponse
    {
        BarangController::izin('perbaiki');
        abort_if($item->formularium_id !== $formularium->id, 404);
        $isi = $request->validate([
            'restriksi' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'maks_per_resep' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'kronis' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['dasar_perubahan' => 'Isi minimal satu perubahan.']);
        }

        return $this->satuItem($kelola->ubahItem($request->user(), $item, IfMatch::versi($request), $isi));
    }

    public function imporItem(Request $request, Formularium $formularium, ImporKatalogObat $impor): JsonResponse
    {
        BarangController::izin('input');
        $isi = BarangController::validasiImpor($request);

        return new JsonResponse(['data' => $impor->item($request->user(), $formularium, $isi['mode'], (string) file_get_contents($isi['berkas']->getRealPath()), $isi['dasar_perubahan'])]);
    }

    /** @return array<string, mixed> */
    private static function bentuk(Formularium $f): array
    {
        return [
            'id' => $f->id, 'kode' => $f->kode, 'nama' => $f->nama, 'jenis' => $f->jenis,
            'penjamin' => $f->penjamin ? ['id' => $f->penjamin->id, 'kode' => $f->penjamin->kode, 'nama' => $f->penjamin->nama] : null,
            'berlaku_dari' => $f->berlaku_dari, 'berlaku_sampai' => $f->berlaku_sampai,
            'jumlah_item_aktif' => (int) $f->getAttribute('jumlah_item_aktif'),
            'versi' => (string) $f->revisi, 'diubah_pada' => $f->diubah_pada->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function bentukItem(ItemFormularium $i): array
    {
        $b = $i->barang;

        return [
            'id' => $i->id, 'formularium_id' => $i->formularium_id,
            'barang' => ['id' => $b->id, 'kode' => $b->kode, 'nama' => $b->nama, 'generik_paten' => $b->generik_paten, 'zat_aktif' => $b->zat_aktif,
                'kekuatan' => $b->kekuatan, 'sediaan' => $b->sediaan, 'satuan_terkecil' => $b->satuan_terkecil, 'status' => $b->status],
            'restriksi' => $i->restriksi, 'maks_per_resep' => $i->maks_per_resep, 'kronis' => $i->kronis, 'status' => $i->status,
            'versi' => (string) $i->revisi, 'diubah_pada' => $i->diubah_pada->toIso8601String(),
        ];
    }

    private function satu(Formularium $f, int $status = 200): JsonResponse
    {
        $f = Formularium::query()->with('penjamin')->withCount(['item as jumlah_item_aktif' => fn ($q) => $q->where('status', 'aktif')])->findOrFail($f->id);

        return (new JsonResponse(['data' => self::bentuk($f)], $status))->setEtag((string) $f->revisi)->setPrivate();
    }

    private function satuItem(ItemFormularium $item, int $status = 200): JsonResponse
    {
        $item = ItemFormularium::query()->with('barang')->findOrFail($item->id);

        return (new JsonResponse(['data' => self::bentukItem($item)], $status))->setEtag((string) $item->revisi)->setPrivate();
    }
}
