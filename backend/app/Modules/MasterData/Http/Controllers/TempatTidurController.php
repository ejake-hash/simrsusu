<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Database\Like;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\MasterData\Application\KelolaTempatTidur;
use App\Modules\MasterData\Application\PohonLokasi;
use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Http\Resources\LokasiResource;
use App\Modules\MasterData\Models\Kamar;
use App\Modules\MasterData\Models\TempatTidur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/v1/tempat-tidur (kontrak v0.5.0, tiket 1.8b). */
final class TempatTidurController
{
    use IzinOrganisasi;

    private const JENIS_KELAMIN = ['campur', 'laki_laki', 'perempuan'];

    public function __construct(private readonly LokasiResource $resource) {}

    public function index(Request $request, PohonLokasi $pohon): JsonResponse
    {
        $this->izin('lihat');
        $filter = $request->validate([
            'kamar_id' => ['nullable', 'uuid'],
            'bangsal_id' => ['nullable', 'uuid'],
            'lokasi_id' => ['nullable', 'uuid'],
            'kelas_rawat_id' => ['nullable', 'uuid'],
            'status_operasional' => ['nullable', Rule::in(['belum_berlaku', 'aktif', 'akan_ditutup', 'ditutup'])],
            'cari' => ['nullable', 'string', 'max:100'],
        ]);
        $query = TempatTidur::query()
            ->when($filter['kamar_id'] ?? null, fn ($q, $id) => $q->where('kamar_id', $id))
            ->when($filter['bangsal_id'] ?? null, fn ($q, $id) => $q->whereIn('kamar_id', Kamar::query()->where('bangsal_id', $id)->select('id')))
            ->when($filter['lokasi_id'] ?? null, fn ($q, $id) => $q->whereIn('lokasi_id', $pohon->subpohon($id)))
            ->when($filter['kelas_rawat_id'] ?? null, fn ($q, $id) => $q->where('kelas_rawat_id', $id))
            ->when($filter['status_operasional'] ?? null, fn ($q, $status) => $this->saringStatus($q, $status))
            ->when($filter['cari'] ?? null, fn ($q, $cari) => $q->whereRaw('kode ILIKE ?', [Like::memuat($cari)]));

        return Paginasi::respons($query, $request, 'kode', arah: 'asc', bentuk: fn ($baris) => $this->resource->tempatTidur($baris));
    }

    public function show(TempatTidur $tempatTidur): JsonResponse
    {
        $this->izin('lihat');

        return $this->satu($tempatTidur);
    }

    public function store(Request $request, KelolaTempatTidur $kelola): JsonResponse
    {
        $this->izin('input');
        $isi = $request->validate([
            'kamar_id' => ['required', 'uuid'],
            'kode' => ['required', ...IsianOrganisasi::KODE_LOKASI],
            'kelas_rawat_id' => ['required', 'uuid'],
            'aturan_jenis_kelamin' => ['nullable', Rule::in(self::JENIS_KELAMIN)],
            'isolasi' => ['sometimes', 'boolean'],
            'ekstra' => ['sometimes', 'boolean'],
            'berlaku_dari' => ['nullable', 'date_format:Y-m-d'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        [$tt, $peringatan] = $kelola->buat($request->user(), $isi);

        return $this->satu($tt, 201, ['peringatan' => $peringatan]);
    }

    public function update(Request $request, TempatTidur $tempatTidur, KelolaTempatTidur $kelola): JsonResponse
    {
        $this->izin('perbaiki');
        $isi = $request->validate([
            'kelas_rawat_id' => ['sometimes', 'uuid'],
            'aturan_jenis_kelamin' => ['sometimes', Rule::in(self::JENIS_KELAMIN)],
            'isolasi' => ['sometimes', 'boolean'],
            'ekstra' => ['sometimes', 'boolean'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        $this->minimalSatuPerubahan($isi);

        return $this->satu($kelola->ubah($request->user(), $tempatTidur, IfMatch::versi($request), $isi));
    }

    public function tutup(Request $request, TempatTidur $tempatTidur, KelolaTempatTidur $kelola): JsonResponse
    {
        $this->izin('perbaiki');
        $isi = $request->validate([
            'berlaku_dari' => ['required', 'date_format:Y-m-d'],
            'alasan' => ['required', 'string', 'min:5', 'max:500'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);

        return $this->satu($kelola->tutup($request->user(), $tempatTidur, IfMatch::versi($request), $isi['berlaku_dari'], $isi['alasan'], $isi['dasar_perubahan']));
    }

    /** Sama dengan LokasiResource::statusOperasional, dalam SQL. */
    private function saringStatus(Builder $q, string $status): void
    {
        $hariIni = SuntinganBerversi::hariIni();
        match ($status) {
            'ditutup' => $q->where('berlaku_sampai', '<', $hariIni),
            'akan_ditutup' => $q->where('berlaku_sampai', '>=', $hariIni),
            'belum_berlaku' => $q->whereNull('berlaku_sampai')->where('berlaku_dari', '>', $hariIni),
            default => $q->whereNull('berlaku_sampai')->where('berlaku_dari', '<=', $hariIni),
        };
    }

    /** @param  array<string, mixed>  $tambahan */
    private function satu(TempatTidur $tt, int $status = 200, array $tambahan = []): JsonResponse
    {
        $tt->refresh();

        return $this->respons($this->resource->tempatTidur(collect([$tt]))[0], $tt->revisi, $status, $tambahan);
    }
}
