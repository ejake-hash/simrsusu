<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Database\Like;
use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Application\PemakaianCakupan;
use App\Modules\MasterData\Application\KelolaUnit;
use App\Modules\MasterData\Application\LingkupUnit;
use App\Modules\MasterData\Application\PohonUnit;
use App\Modules\MasterData\Http\Resources\OrganisasiResource;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** /api/v1/unit-organisasi (kontrak v0.3.0, tiket 1.8a). */
final class UnitOrganisasiController
{
    /** Pengelola akun butuh pohon unit untuk memilih cakupan penugasan. */
    private const IZIN_LIHAT = ['master_data.organisasi.lihat', 'pengaturan.akun.input'];

    private const KODE = ['string', 'regex:/^[A-Z0-9][A-Z0-9._-]{0,29}$/'];

    public function __construct(
        private readonly LingkupUnit $lingkup,
        private readonly OrganisasiResource $resource,
    ) {}

    public function index(Request $request, PohonUnit $pohon, PemakaianCakupan $pemakaian): JsonResponse
    {
        $filter = $request->validate([
            'induk_id' => ['nullable', 'uuid'],
            // Nilai query string: true/false/1/0 (aturan `boolean` Laravel menolak "true").
            'akar' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
            'cari' => ['nullable', 'string', 'max:100'],
            'jenis' => ['nullable', Rule::in(UnitOrganisasi::JENIS)],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
            'milik_saya' => ['nullable', Rule::in(['true', 'false', '1', '0'])],
        ]);
        $query = UnitOrganisasi::query();

        if ($request->boolean('milik_saya')) {
            // Pemilih unit kerja: hanya unit penugasan akun ini, tanpa izin data induk.
            $query->whereIn('id', $pemakaian->unitBerlaku($request->user()));
        } else {
            abort_unless(Gate::any(self::IZIN_LIHAT), 403);
            $akar = $this->lingkup->akarTerjangkau($request->user(), self::IZIN_LIHAT);
            if ($akar !== null) {
                $query->whereIn('id', $pohon->subpohon($akar));
            }
            if ($request->boolean('akar') && empty($filter['induk_id'])) {
                // Akar pohon yang terlihat: unit teratas, atau akar subpohon cakupan akun ini.
                $akar === null ? $query->whereNull('induk_id') : $query->whereIn('id', $akar);
            }
            $query->when($filter['induk_id'] ?? null, fn ($q, $induk) => $q->where('induk_id', $induk))
                ->when($filter['jenis'] ?? null, fn ($q, $jenis) => $q->where('jenis', $jenis))
                ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($filter['cari'] ?? null, fn ($q, $cari) => $q->where(fn ($q) => $q
                    ->whereRaw('kode ILIKE ?', [Like::memuat($cari)])->orWhereRaw('nama ILIKE ?', [Like::memuat($cari)])));
        }

        return Paginasi::respons($query, $request, 'kode', arah: 'asc', bentuk: fn ($baris) => $this->resource->unit($baris));
    }

    public function show(Request $request, UnitOrganisasi $unit): JsonResponse
    {
        abort_unless(Gate::any(self::IZIN_LIHAT), 403);
        if (! $this->lingkup->memuat($this->lingkup->akarTerjangkau($request->user(), self::IZIN_LIHAT), $unit->id)) {
            throw new ApiError(404, 'tidak_ditemukan', 'Data tidak ditemukan.');
        }

        return $this->satu($unit);
    }

    public function store(Request $request, KelolaUnit $kelola): JsonResponse
    {
        $isi = $request->validate([
            'induk_id' => ['present', 'nullable', 'uuid', Rule::exists(UnitOrganisasi::class, 'id')],
            'kode' => ['required', ...self::KODE],
            'nama' => ['required', 'string', 'min:2', 'max:150'],
            'jenis' => ['required', Rule::in(UnitOrganisasi::JENIS)],
            'berlaku_dari' => ['nullable', 'date_format:Y-m-d'],
            'dasar_perubahan' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        return $this->satu($kelola->buat($request->user(), $isi), 201);
    }

    public function update(Request $request, UnitOrganisasi $unit, KelolaUnit $kelola): JsonResponse
    {
        $isi = $request->validate([
            'induk_id' => ['sometimes', 'nullable', 'uuid', Rule::exists(UnitOrganisasi::class, 'id')],
            'kode' => ['sometimes', ...self::KODE],
            'nama' => ['sometimes', 'string', 'min:2', 'max:150'],
            'jenis' => ['sometimes', Rule::in(UnitOrganisasi::JENIS)],
            'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])],
            'berlaku_dari' => ['sometimes', 'date_format:Y-m-d'],
            'dasar_perubahan' => ['required', 'string', 'min:3', 'max:200'],
        ]);
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['nama' => 'Isi minimal satu perubahan.']);
        }
        $versi = IfMatch::versi($request);

        return $this->satu($kelola->ubah($request->user(), $unit, $versi, $isi));
    }

    private function satu(UnitOrganisasi $unit, int $status = 200): JsonResponse
    {
        $unit->refresh();
        $respons = new JsonResponse(['data' => $this->resource->unit(collect([$unit]))[0]], $status);

        return $respons->setEtag((string) $unit->revisi)->setPrivate();
    }
}
