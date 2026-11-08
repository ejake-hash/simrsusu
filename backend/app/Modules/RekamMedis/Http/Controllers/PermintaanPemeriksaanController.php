<?php

namespace App\Modules\RekamMedis\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\RekamMedis\Application\AksesCatatan;
use App\Modules\RekamMedis\Application\KelolaPermintaanPemeriksaan;
use App\Modules\RekamMedis\Models\PermintaanPemeriksaan;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Order penunjang bersama (tiket 2.16, kontrak v0.26.0): pesan dari kunjungan, daftar per episode, batal. Respons `no-store`. */
final class PermintaanPemeriksaanController
{
    private const IZIN_ORDER = 'rawat_jalan.pemeriksaan.order';

    public function __construct(
        private readonly KelolaPermintaanPemeriksaan $kelola,
        private readonly AksesCatatan $akses,
        private readonly RingkasanPegawai $pegawai,
    ) {}

    public function store(Request $request, string $kunjungan): JsonResponse
    {
        $k = AsesmenAwalController::kunjungan([self::IZIN_ORDER.'.input'], $kunjungan);
        $isian = $request->validate([
            'jenis' => ['required', Rule::in(['lab', 'rad'])],
            'prioritas' => ['required', Rule::in(['rutin', 'cito'])],
            'diagnosis_kerja' => ['required', 'string', 'min:3', 'max:500'],
            'catatan' => ['nullable', 'string', 'max:1000'],
            'alasan_ganda' => ['nullable', 'string', 'min:5', 'max:500'],
            'item' => ['required', 'array', 'min:1', 'max:50'],
            'item.*' => ['array:tarif_id'],
            'item.*.tarif_id' => ['required', 'uuid', 'distinct'],
        ]);

        return $this->satu($this->kelola->pesan($request->user(), $k->id, $isian), 201);
    }

    public function episode(Request $request, string $episode): JsonResponse
    {
        $izin = KelolaPermintaanPemeriksaan::IZIN_LIHAT;
        Gate::authorize($izin);
        abort_unless(DB::table('kunjungan.episode')->where('id', $episode)->exists(), 404);
        $pengguna = $request->user();
        if (! $this->akses->bolehBacaKunjungan($pengguna, [$izin], ['episode_id' => $episode])) {
            throw new ApiError(403, 'akses_ditolak', 'Episode ini di luar cakupan akses Anda.');
        }
        $filter = $request->validate(['status' => ['nullable', Rule::in(['dipesan', 'batal'])]]);
        $sensitif = KelolaPermintaanPemeriksaan::unitSensitifTerbuka($pengguna);
        $terlihat = DB::table('kunjungan.kunjungan AS k')->join('inti.poliklinik AS pl', 'pl.id', '=', 'k.poli_id')->where('k.episode_id', $episode)
            ->where(fn ($q) => $q->whereNull('pl.nama_netral')->orWhereIn('pl.unit_organisasi_id', $sensitif))->select('k.id');
        $query = PermintaanPemeriksaan::query()->where('episode_id', $episode)->whereIn('kunjungan_id', $terlihat)
            ->when($filter['status'] ?? null, fn ($q, $s) => $q->where('status', $s));

        return Paginasi::respons($query, $request, 'dibuat_pada', bentuk: fn (Collection $baris) => $this->bentuk($baris, $pengguna))
            ->setPrivate()->header('Cache-Control', 'no-store, private');
    }

    public function batal(Request $request, string $permintaan): JsonResponse
    {
        Gate::authorize(self::IZIN_ORDER.'.batal');
        $unit = DB::table('permintaan.permintaan_pemeriksaan AS p')->join('kunjungan.kunjungan AS k', 'k.id', '=', 'p.kunjungan_id')
            ->join('inti.poliklinik AS pl', 'pl.id', '=', 'k.poli_id')->where('p.id', $permintaan)->value('pl.unit_organisasi_id')
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Order tidak ditemukan.');
        Gate::authorize(self::IZIN_ORDER.'.batal', new Cakupan('unit', (string) $unit));
        $alasan = $request->validate(['alasan_batal' => ['required', 'string', 'min:5', 'max:500']])['alasan_batal'];

        return $this->satu($this->kelola->batal($request->user(), $permintaan, $alasan));
    }

    /**
     * @param  Collection<int, PermintaanPemeriksaan>  $baris
     * @return list<array<string, mixed>>
     */
    private function bentuk(Collection $baris, Pengguna $pengguna): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $id = $baris->pluck('id')->all();
        $item = DB::table('permintaan.permintaan_item')->whereIn('permintaan_pemeriksaan_id', $id)->orderBy('kode_item')
            ->get(['id', 'permintaan_pemeriksaan_id', 'tarif_id', 'kode_item', 'nama', 'status'])->groupBy('permintaan_pemeriksaan_id');
        $unit = DB::table('kunjungan.kunjungan AS k')->join('inti.poliklinik AS pl', 'pl.id', '=', 'k.poli_id')
            ->whereIn('k.id', $baris->pluck('kunjungan_id')->unique()->values())->pluck('pl.unit_organisasi_id', 'k.id');
        $gate = Gate::forUser($pengguna);
        $bolehBatal = $unit->unique()->mapWithKeys(fn ($u) => [$u => $gate->allows(self::IZIN_ORDER.'.batal', new Cakupan('unit', (string) $u))]);
        $akun = Pengguna::query()->whereIn('id', $baris->pluck('pemesan_id')->merge($baris->pluck('dibatalkan_oleh'))->filter()->unique()->values())
            ->get(['id', 'pegawai_id', 'nama_pengguna']);
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id')->filter()->values()->all());
        $pelaku = $akun->mapWithKeys(fn (Pengguna $a) => [$a->id => ['id' => $a->id, 'nama' => $nama[$a->pegawai_id] ?? $a->nama_pengguna]]);

        return $baris->map(fn (PermintaanPemeriksaan $p) => [
            'id' => $p->id, 'kunjungan_id' => $p->kunjungan_id, 'episode_id' => $p->episode_id, 'jenis' => $p->jenis, 'prioritas' => $p->prioritas,
            'diagnosis_kerja' => $p->diagnosis_kerja, 'catatan' => $p->catatan, 'alasan_ganda' => $p->alasan_ganda, 'status' => $p->status,
            'pemesan' => $pelaku[$p->pemesan_id] ?? null, 'dipesan_pada' => $p->dibuat_pada->toIso8601String(),
            'item' => ($item[$p->id] ?? collect())->map(fn ($i) => ['id' => $i->id, 'tarif_id' => $i->tarif_id, 'kode_item' => $i->kode_item,
                'nama' => $i->nama, 'status' => $i->status])->values()->all(),
            'alasan_batal' => $p->alasan_batal, 'dibatalkan_oleh' => $p->dibatalkan_oleh === null ? null : ($pelaku[$p->dibatalkan_oleh] ?? null),
            'dibatalkan_pada' => $p->dibatalkan_pada?->toIso8601String(),
            'dapat' => ['batal' => $p->status === 'dipesan' && ($bolehBatal[$unit[$p->kunjungan_id] ?? ''] ?? false)],
        ])->values()->all();
    }

    private function satu(PermintaanPemeriksaan $p, int $status = 200): JsonResponse
    {
        return (new JsonResponse(['data' => $this->bentuk(collect([$p]), request()->user())[0]], $status))
            ->setPrivate()->header('Cache-Control', 'no-store, private');
    }
}
