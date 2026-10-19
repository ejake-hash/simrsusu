<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\MasterData\Application\KelolaJadwalPraktik;
use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Http\Resources\JadwalPraktikResource;
use App\Modules\MasterData\Models\JadwalPraktik;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** /api/v1/jadwal-praktik (kontrak v0.17.0, tiket 2.4). Izin master_data.jadwal_praktik.* cakupan `rs`, lewat Gate → AccessEvaluator. */
final class JadwalPraktikController
{
    private const JAM = ['string', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'];

    public function __construct(private readonly JadwalPraktikResource $resource) {}

    public function index(Request $request): JsonResponse
    {
        $this->izin('lihat');
        $filter = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'poli_id' => ['nullable', 'uuid'],
            'pegawai_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
        ]);
        $tanggal = $filter['tanggal'] ?? null;
        if ($tanggal !== null && Carbon::parse($tanggal)->lt(Carbon::parse(SuntinganBerversi::hariIni())->subDays(366))) {
            throw ValidationException::withMessages(['tanggal' => 'Tanggal terlalu lampau (maksimal 366 hari ke belakang).']);
        }
        $query = JadwalPraktik::query()->where('status', $filter['status'] ?? 'aktif')
            ->when($filter['poli_id'] ?? null, fn ($q, $id) => $q->where('poli_id', $id))
            ->when($filter['pegawai_id'] ?? null, fn ($q, $id) => $q->where('pegawai_id', $id))
            ->when($tanggal, fn ($q, $t) => $q->where('hari', Carbon::parse($t)->isoWeekday())
                ->where('berlaku_dari', '<=', $t)->where(fn ($w) => $w->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $t)));

        // Urutan keyset (dibuat_pada, id) menurut 14.1.1; layar mengurutkan per poli/hari/jam di sisi klien.
        return Paginasi::respons($query, $request, 'dibuat_pada', arah: 'asc',
            bentuk: fn ($baris) => $this->resource->jadwal($baris, $tanggal));
    }

    public function store(Request $request, KelolaJadwalPraktik $kelola): JsonResponse
    {
        $this->izin('input');
        $isi = $request->validate([
            'pegawai_id' => ['required', 'uuid'],
            'poli_id' => ['required', 'uuid'],
            'ruang_id' => ['nullable', 'uuid'],
            'hari' => ['required', 'integer', 'between:1,7'],
            'jam_mulai' => ['required', ...self::JAM],
            'jam_selesai' => ['required', ...self::JAM],
            'kuota_jkn' => ['required', 'integer', 'between:0,1000'],
            'kuota_non_jkn' => ['required', 'integer', 'between:0,1000'],
            'berlaku_dari' => ['required', 'date_format:Y-m-d'],
            'berlaku_sampai' => ['nullable', 'date_format:Y-m-d'],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);

        return $this->satu($kelola->buat($request->user(), $isi), 201);
    }

    public function update(Request $request, JadwalPraktik $jadwal, KelolaJadwalPraktik $kelola): JsonResponse
    {
        $this->izin('perbaiki');
        $isi = $request->validate([
            'ruang_id' => ['sometimes', 'nullable', 'uuid'],
            'hari' => ['sometimes', 'integer', 'between:1,7'],
            'jam_mulai' => ['sometimes', ...self::JAM],
            'jam_selesai' => ['sometimes', ...self::JAM],
            'kuota_jkn' => ['sometimes', 'integer', 'between:0,1000'],
            'kuota_non_jkn' => ['sometimes', 'integer', 'between:0,1000'],
            'berlaku_dari' => ['sometimes', 'date_format:Y-m-d'],
            'berlaku_sampai' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])],
            'dasar_perubahan' => IsianOrganisasi::DASAR,
        ]);
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['dasar_perubahan' => 'Isi minimal satu perubahan.']);
        }

        return $this->satu($kelola->ubah($request->user(), $jadwal, IfMatch::versi($request), $isi));
    }

    private function izin(string $aksi): void
    {
        abort_unless(Gate::allows("master_data.jadwal_praktik.$aksi", Cakupan::rs()), 403);
    }

    private function satu(JadwalPraktik $jadwal, int $status = 200): JsonResponse
    {
        $jadwal->refresh();

        return (new JsonResponse(['data' => $this->resource->jadwal(collect([$jadwal]))[0]], $status))->setEtag((string) $jadwal->revisi)->setPrivate();
    }
}
