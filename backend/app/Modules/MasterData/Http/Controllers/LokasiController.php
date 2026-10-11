<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Database\Like;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\MasterData\Application\KelolaLokasi;
use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Http\Resources\LokasiResource;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\TempatTidur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/v1/lokasi (kontrak v0.5.0, tiket 1.8b). */
final class LokasiController
{
    use IzinOrganisasi;

    public function __construct(private readonly LokasiResource $resource) {}

    public function index(Request $request): JsonResponse
    {
        $this->izin('lihat');
        $filter = $request->validate([
            'induk_id' => ['nullable', 'uuid'],
            'akar' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
            'cari' => ['nullable', 'string', 'max:100'],
            'jenis' => ['nullable', Rule::in(Lokasi::JENIS)],
            'status' => ['nullable', Rule::in(Lokasi::STATUS)],
        ]);
        $query = Lokasi::query()
            ->when(self::benar($request, 'akar') && empty($filter['induk_id']), fn ($q) => $q->whereNull('induk_id'))
            ->when($filter['induk_id'] ?? null, fn ($q, $induk) => $q->where('induk_id', $induk))
            ->when($filter['jenis'] ?? null, fn ($q, $jenis) => $q->where('jenis', $jenis))
            ->when($filter['status'] ?? null, fn ($q, $status) => $this->saringStatus($q, $status))
            ->when($filter['cari'] ?? null, fn ($q, $cari) => $q->where(fn ($q) => $q
                ->whereRaw('kode_lengkap ILIKE ?', [Like::memuat($cari)])->orWhereRaw('nama ILIKE ?', [Like::memuat($cari)])
                // Kode TT tetap walau ruangnya dipindah, jadi bisa berbeda dari kode_lengkap node-nya.
                ->orWhereIn('id', TempatTidur::query()->whereRaw('kode ILIKE ?', [Like::memuat($cari)])->select('lokasi_id'))));

        return Paginasi::respons($query, $request, 'kode_lengkap', arah: 'asc', bentuk: fn ($baris) => $this->resource->lokasi($baris));
    }

    public function show(Lokasi $lokasi): JsonResponse
    {
        $this->izin('lihat');

        return $this->satu($lokasi);
    }

    public function store(Request $request, KelolaLokasi $kelola): JsonResponse
    {
        $this->izin('input');
        $isi = $request->validate([
            'induk_id' => ['present', 'nullable', 'uuid', Rule::exists(Lokasi::class, 'id')],
            'jenis' => ['required', Rule::in(Lokasi::JENIS)],
            'kode' => ['required', ...IsianOrganisasi::KODE_LOKASI],
            'nama' => ['required', 'string', 'min:2', 'max:150'],
            'fungsi' => ['nullable', Rule::in(Lokasi::FUNGSI)],
            'unit_organisasi_id' => ['nullable', 'uuid'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);

        return $this->satu($kelola->buat($request->user(), $isi), 201);
    }

    public function update(Request $request, Lokasi $lokasi, KelolaLokasi $kelola): JsonResponse
    {
        $this->izin('perbaiki');
        $isi = $request->validate([
            'induk_id' => ['sometimes', 'nullable', 'uuid', Rule::exists(Lokasi::class, 'id')],
            'kode' => ['sometimes', ...IsianOrganisasi::KODE_LOKASI],
            'nama' => ['sometimes', 'string', 'min:2', 'max:150'],
            'fungsi' => ['sometimes', 'nullable', Rule::in(Lokasi::FUNGSI)],
            'unit_organisasi_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', Rule::in(Lokasi::STATUS)],
            'status_berlaku_dari' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        $this->minimalSatuPerubahan($isi);

        return $this->satu($kelola->ubah($request->user(), $lokasi, IfMatch::versi($request), $isi));
    }

    /** Node TT: status mengikuti masa berlaku TT-nya (ditutup mulai hari pertama tidak dihitung). */
    private function saringStatus(Builder $q, string $status): void
    {
        $ttTutup = fn ($s) => $s->from('inti.tempat_tidur')->whereColumn('inti.tempat_tidur.lokasi_id', 'inti.lokasi.id')
            ->where('berlaku_sampai', '<', SuntinganBerversi::hariIni());
        $q->where(fn ($q) => match ($status) {
            'ditutup' => $q->where(fn ($q) => $q->where('jenis', '!=', 'tempat_tidur')->where('status', 'ditutup'))
                ->orWhere(fn ($q) => $q->where('jenis', 'tempat_tidur')->whereExists($ttTutup)),
            'aktif' => $q->where(fn ($q) => $q->where('jenis', '!=', 'tempat_tidur')->where('status', 'aktif'))
                ->orWhere(fn ($q) => $q->where('jenis', 'tempat_tidur')->whereNotExists($ttTutup)),
            default => $q->where('jenis', '!=', 'tempat_tidur')->where('status', $status),
        });
    }

    private function satu(Lokasi $lokasi, int $status = 200): JsonResponse
    {
        $lokasi->refresh();

        return $this->respons($this->resource->lokasi(collect([$lokasi]))[0], $lokasi->revisi, $status);
    }
}
