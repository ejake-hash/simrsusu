<?php

namespace App\Modules\Antrean\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Antrean\Models\Antrean;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/rawat-jalan/worklist (tiket 2.13, kontrak v0.23.0; FR-07-01, 7.07.1). Cakupan baris dipaksa server: akun
 * yang pegawainya berjadwal praktik di poli itu pada hari itu hanya melihat pasiennya; selain itu seluruh poli (perawat).
 */
final class WorklistController
{
    public function __construct(private readonly RingkasanPegawai $pegawai) {}

    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('rawat_jalan.worklist.lihat');
        $filter = $request->validate([
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'poli_id' => ['required', 'uuid'],
            'tahap' => ['nullable', Rule::in(['perawat', 'dokter', 'selesai'])],
        ]);
        $unit = DB::table('inti.poliklinik')->where('id', $filter['poli_id'])->value('unit_organisasi_id')
            ?? throw new ApiError(422, 'validasi_gagal', 'Poliklinik tidak ditemukan.', [['kolom' => 'poli_id', 'pesan' => 'Poliklinik tidak ditemukan.']]);
        Gate::authorize('rawat_jalan.worklist.lihat', new Cakupan('unit', $unit));

        /** @var Pengguna $pengguna */
        $pengguna = $request->user();
        $query = Antrean::query()->where('titik', 'poli')->where('poli_id', $filter['poli_id'])->where('tanggal', $filter['tanggal'])
            ->where('status', '<>', 'batal');
        if ($this->dokterBerjadwal($pengguna->pegawai_id, $filter['poli_id'], $filter['tanggal'])) {
            $query->where('pegawai_id', $pengguna->pegawai_id);
        }
        $selesai = fn (Builder $q) => $q->whereIn('kunjungan_id', DB::table('kunjungan.kunjungan')->where('status', 'selesai')->select('id'));
        match ($filter['tahap'] ?? null) {
            'selesai' => $selesai($query),
            'perawat', 'dokter' => $query->where('tahap', $filter['tahap'])->whereNot(fn ($q) => $selesai($q)),
            default => null,
        };

        return Paginasi::respons($query, $request, 'urutan', arah: 'asc', bentuk: fn ($baris) => $this->bentuk($baris))
            ->header('Cache-Control', 'no-store');
    }

    private function dokterBerjadwal(?string $pegawaiId, string $poliId, string $tanggal): bool
    {
        return $pegawaiId !== null && DB::table('kunjungan.jadwal_praktik')->where('pegawai_id', $pegawaiId)->where('poli_id', $poliId)
            ->where('status', 'aktif')->where('hari', Carbon::parse($tanggal)->dayOfWeekIso)->where('berlaku_dari', '<=', $tanggal)
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $tanggal))->exists();
    }

    /**
     * @param  Collection<int, Antrean>  $baris
     * @return list<array<string, mixed>>
     */
    private function bentuk(Collection $baris): array
    {
        $kunjunganId = $baris->pluck('kunjungan_id')->filter()->unique();
        $kunjungan = DB::table('kunjungan.kunjungan AS k')->join('pasien.pasien AS p', 'p.id', '=', 'k.pasien_id')->whereIn('k.id', $kunjunganId)
            ->get(['k.id', 'k.status', 'p.id AS pasien_id', 'p.no_rm', 'p.nama', 'p.jenis_kelamin', 'p.tanggal_lahir'])->keyBy('id');
        $asesmen = DB::table('klinis.catatan_klinis')->whereIn('kunjungan_id', $kunjunganId)->where('jenis', 'asesmen')->where('profesi', 'perawat')
            ->get(['id', 'kunjungan_id', 'status'])->keyBy('kunjungan_id');
        $alergi = DB::table('klinis.alergi')->whereIn('pasien_id', $kunjungan->pluck('pasien_id'))->where('status', 'aktif')
            ->distinct()->pluck('pasien_id')->flip();
        $menunggu = DB::table('kunjungan.antrean_jejak')->whereIn('antrean_id', $baris->pluck('id'))->where('status', 'menunggu')
            ->groupBy('antrean_id')->selectRaw('antrean_id, max(terjadi_pada) AS pada')->pluck('pada', 'antrean_id');
        $dokter = $this->pegawai->ringkas($baris->pluck('pegawai_id')->filter());

        return $baris->map(function (Antrean $a) use ($kunjungan, $asesmen, $alergi, $menunggu, $dokter) {
            $k = $kunjungan[$a->kunjungan_id];
            $as = $asesmen[$a->kunjungan_id] ?? null;

            return [
                'antrean_id' => $a->id, 'kunjungan_id' => $a->kunjungan_id, 'nomor_tampil' => $a->nomor_tampil,
                'pasien' => ['id' => $k->pasien_id, 'no_rm' => $k->no_rm, 'nama' => $k->nama, 'jenis_kelamin' => $k->jenis_kelamin,
                    'tanggal_lahir' => $k->tanggal_lahir === null ? null : substr((string) $k->tanggal_lahir, 0, 10)],
                'dokter' => $a->pegawai_id === null ? null : ($dokter[$a->pegawai_id] ?? null),
                'status_antrean' => $a->status,
                'tahap' => $k->status === 'selesai' ? 'selesai' : $a->tahap,
                'asesmen_awal' => $as === null ? null : ['id' => $as->id, 'status' => $as->status],
                'ada_alergi' => isset($alergi[$k->pasien_id]),
                'terdaftar_pada' => $a->dibuat_pada->toIso8601String(),
                'menunggu_sejak' => Carbon::parse($menunggu[$a->id] ?? $a->dibuat_pada)->toIso8601String(),
            ];
        })->values()->all();
    }
}
