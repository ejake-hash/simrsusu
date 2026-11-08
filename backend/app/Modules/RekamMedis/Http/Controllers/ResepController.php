<?php

namespace App\Modules\RekamMedis\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Models\Pasien;
use App\Modules\RekamMedis\Application\KelolaResep;
use App\Modules\RekamMedis\Application\PemeriksaResep;
use App\Modules\RekamMedis\Models\Resep;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** E-resep rawat jalan (tiket 2.17, kontrak v0.27.0): resep per kunjungan, antrean depo, ubah draf, sahkan, batal. `no-store`. */
final class ResepController
{
    private const IZIN = 'rawat_jalan.pemeriksaan.resep';

    public function __construct(
        private readonly KelolaResep $kelola,
        private readonly PemeriksaResep $pemeriksa,
        private readonly RingkasanPegawai $pegawai,
    ) {}

    public function depo(): JsonResponse
    {
        if (! Gate::allows(self::IZIN.'.input') && ! Gate::allows(KelolaResep::IZIN_FARMASI)) {
            throw new ApiError(403, 'akses_ditolak', 'Anda tidak memiliki akses ke halaman ini.');
        }
        $depo = DB::table('inti.unit_organisasi')->where('jenis', 'depo')->where('status', 'aktif')->orderBy('kode')->limit(200)
            ->get(['id', 'kode', 'nama']);

        return response()->json(['data' => $depo]);
    }

    public function kunjungan(Request $request, string $kunjungan): JsonResponse
    {
        $k = AsesmenAwalController::kunjungan([KelolaResep::IZIN_LIHAT], $kunjungan);
        $pengguna = $request->user();

        return Paginasi::respons(Resep::query()->where('kunjungan_id', $k->id), $request, 'dibuat_pada',
            bentuk: fn (Collection $baris) => $this->bentuk($baris, $pengguna))->setPrivate()->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, string $kunjungan): JsonResponse
    {
        $k = AsesmenAwalController::kunjungan([self::IZIN.'.input'], $kunjungan);
        $isian = $request->validate([
            'depo_id' => ['required', 'uuid'],
            'racikan' => ['sometimes', 'boolean'],
            'catatan' => ['nullable', 'string', 'max:1000'],
            ...self::aturanButir(true),
        ]);

        return $this->satu($this->kelola->buat($request->user(), $k->id, $isian), 201);
    }

    public function depoHarian(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'depo_id' => ['required', 'uuid'],
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(['sah', 'batal'])],
        ]);
        Gate::authorize(KelolaResep::IZIN_FARMASI, new Cakupan('unit', $filter['depo_id']));
        $pengguna = $request->user();
        $query = Resep::query()->where('depo_id', $filter['depo_id'])->whereNotNull('masuk_pada')->where('status', $filter['status'] ?? 'sah')
            ->whereRaw('(masuk_pada AT TIME ZONE ?)::date = ?', [config('instalasi.zona_waktu'), $filter['tanggal'] ?? SuntinganBerversi::hariIni()]);

        return Paginasi::respons($query, $request, 'masuk_pada', arah: 'asc', bentuk: fn (Collection $baris) => $this->bentuk($baris, $pengguna))
            ->setPrivate()->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $resep): JsonResponse
    {
        $this->izinResep('input', $resep);
        $isian = $request->validate([
            'depo_id' => ['sometimes', 'uuid'],
            'racikan' => ['sometimes', 'boolean'],
            'catatan' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ...self::aturanButir(false),
        ]);
        if ($isian === []) {
            throw new ApiError(422, 'validasi_gagal', 'Tidak ada yang diubah.', [['kolom' => 'item', 'pesan' => 'Kirim minimal satu isian.']]);
        }

        return $this->satu($this->kelola->ubah($request->user(), $resep, IfMatch::versi($request), $isian));
    }

    public function sahkan(Request $request, string $resep): JsonResponse
    {
        $this->izinResep('sahkan', $resep);
        $isian = $request->validate([
            'pin' => ['nullable', 'string', 'max:20'],
            'pernyataan_disetujui' => ['required', 'accepted'],
            'tanggapan' => ['sometimes', 'array', 'max:200'],
            'tanggapan.*' => ['array:kunci,alasan'],
            'tanggapan.*.kunci' => ['required', 'string', 'max:200'],
            'tanggapan.*.alasan' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return $this->satu($this->kelola->sahkan($request, $request->user(), $resep, IfMatch::versi($request), $isian['pin'] ?? null,
            array_values($isian['tanggapan'] ?? [])));
    }

    public function batal(Request $request, string $resep): JsonResponse
    {
        $this->izinResep('batal', $resep);
        $alasan = $request->validate(['alasan_batal' => ['required', 'string', 'min:5', 'max:500']])['alasan_batal'];

        return $this->satu($this->kelola->batal($request->user(), $resep, $alasan));
    }

    /** @return array<string, list<mixed>> */
    private static function aturanButir(bool $wajib): array
    {
        return [
            'item' => [$wajib ? 'required' : 'sometimes', 'array', 'min:1', 'max:30'],
            'item.*' => ['array:barang_id,dosis,frekuensi_per_hari,jumlah_per_pakai,hari,jumlah,rute,aturan_pakai,catatan'],
            'item.*.barang_id' => ['required', 'uuid', 'distinct'],
            'item.*.dosis' => ['nullable', 'string', 'max:100'],
            'item.*.frekuensi_per_hari' => ['required', 'integer', 'between:1,24'],
            'item.*.jumlah_per_pakai' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'item.*.hari' => ['required', 'integer', 'between:1,365'],
            'item.*.jumlah' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'item.*.rute' => ['nullable', 'string', 'max:50'],
            'item.*.aturan_pakai' => ['nullable', 'string', 'max:200'],
            'item.*.catatan' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** Izin bagian resep di cakupan unit kunjungan resep; 404 bila tidak ada. */
    private function izinResep(string $aksi, string $id): void
    {
        Gate::authorize(self::IZIN.".$aksi");
        $unit = DB::table('farmasi.resep AS r')->join('kunjungan.kunjungan AS k', 'k.id', '=', 'r.kunjungan_id')
            ->join('inti.poliklinik AS pl', 'pl.id', '=', 'k.poli_id')->where('r.id', $id)->value('pl.unit_organisasi_id')
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Resep tidak ditemukan.');
        Gate::authorize(self::IZIN.".$aksi", new Cakupan('unit', (string) $unit));
    }

    /**
     * @param  Collection<int, Resep>  $baris
     * @return list<array<string, mixed>>
     */
    private function bentuk(Collection $baris, Pengguna $pengguna): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $id = $baris->pluck('id')->all();
        $item = DB::table('farmasi.resep_item AS i')->join('logistik.barang AS b', 'b.id', '=', 'i.barang_id')->whereIn('i.resep_id', $id)
            ->whereNull('i.diganti_pada')->orderBy('i.urutan')
            ->get(['i.*', 'b.kode AS b_kode', 'b.nama AS b_nama', 'b.zat_aktif', 'b.kekuatan', 'b.sediaan', 'b.satuan_terkecil'])->groupBy('resep_id');
        $tersimpan = DB::table('farmasi.peringatan_resep AS p')->join('farmasi.resep_item AS i', 'i.id', '=', 'p.resep_item_id')
            ->whereIn('i.resep_id', $id)->orderBy('i.urutan')->orderBy('p.kunci')->get(['p.*', 'i.resep_id'])->groupBy('resep_id');
        $kunjungan = DB::table('kunjungan.kunjungan AS k')->join('inti.poliklinik AS pl', 'pl.id', '=', 'k.poli_id')
            ->whereIn('k.id', $baris->pluck('kunjungan_id')->unique()->values())
            ->get(['k.id', 'k.pegawai_id', 'pl.unit_organisasi_id', 'pl.nama', 'pl.nama_netral'])->keyBy('id');
        $pasien = Pasien::query()->whereIn('id', $baris->pluck('pasien_id')->unique()->values())->get(['id', 'no_rm', 'nama', 'jenis_kelamin', 'tanggal_lahir'])->keyBy('id');
        $depo = DB::table('inti.unit_organisasi')->whereIn('id', $baris->pluck('depo_id')->unique()->values())->get(['id', 'kode', 'nama'])->keyBy('id');
        $antrean = DB::table('kunjungan.antrean')->whereIn('id', $baris->pluck('antrean_id')->filter()->unique()->values())->get(['id', 'nomor_tampil', 'status'])->keyBy('id');
        $dokter = $this->pegawai->ringkas($baris->pluck('dokter_id')->filter()->unique());
        $akun = Pengguna::query()->whereIn('id', $baris->pluck('penulis_id')->merge($baris->pluck('dibatalkan_oleh'))->merge($tersimpan->flatten()->pluck('ditanggapi_oleh'))
            ->filter()->unique()->values())->get(['id', 'pegawai_id', 'nama_pengguna']);
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id')->filter()->values()->all());
        $pelaku = $akun->mapWithKeys(fn (Pengguna $a) => [$a->id => ['id' => $a->id, 'nama' => $nama[$a->pegawai_id] ?? $a->nama_pengguna]]);
        $gate = Gate::forUser($pengguna);
        $boleh = fn (string $aksi, string $unit) => $gate->allows(self::IZIN.".$aksi", new Cakupan('unit', $unit));

        return $baris->map(function (Resep $r) use ($item, $tersimpan, $kunjungan, $pasien, $depo, $antrean, $dokter, $pelaku, $pengguna, $boleh) {
            $k = $kunjungan[$r->kunjungan_id];
            $p = $pasien[$r->pasien_id];
            $dokterJadwal = $pengguna->pegawai_id !== null && $k->pegawai_id === $pengguna->pegawai_id;
            $pemilik = $dokterJadwal || $r->penulis_id === $pengguna->id;
            $peringatan = $r->status === 'draf'
                ? array_map(fn (array $x) => [...$x, 'tanggapan' => null], $this->pemeriksa->periksa($pengguna, $r))
                : ($tersimpan[$r->id] ?? collect())->map(fn ($x) => ['kunci' => $x->kunci, 'resep_item_id' => $x->resep_item_id, 'jenis' => $x->jenis,
                    'tingkat' => $x->tingkat, 'pesan' => $x->pesan, 'tanggapan' => ['alasan' => $x->tanggapan, 'oleh' => $pelaku[$x->ditanggapi_oleh] ?? null,
                        'pada' => Carbon::parse($x->ditanggapi_pada)->toIso8601String()]])->values()->all();
            $a = $r->antrean_id === null ? null : $antrean[$r->antrean_id];

            return [
                'id' => $r->id, 'no_resep' => $r->no_resep, 'kunjungan_id' => $r->kunjungan_id,
                'pasien' => ['id' => $p->id, 'no_rm' => $p->no_rm, 'nama' => $p->nama, 'jenis_kelamin' => $p->jenis_kelamin,
                    'tanggal_lahir' => $p->tanggal_lahir?->toDateString()],
                'poliklinik' => ['nama' => $k->nama_netral ?? $k->nama],
                'depo' => (array) $depo[$r->depo_id], 'jenis' => $r->jenis, 'racikan' => $r->racikan, 'catatan' => $r->catatan, 'status' => $r->status,
                'penulis' => $pelaku[$r->penulis_id] ?? null, 'dokter' => $r->dokter_id === null ? null : ($dokter[$r->dokter_id] ?? null),
                'dibuat_pada' => $r->dibuat_pada->toIso8601String(), 'disahkan_pada' => $r->masuk_pada?->toIso8601String(),
                'item' => ($item[$r->id] ?? collect())->map(fn ($i) => [
                    'id' => $i->id, 'barang' => ['id' => $i->barang_id, 'kode' => $i->b_kode, 'nama' => $i->b_nama, 'zat_aktif' => $i->zat_aktif,
                        'kekuatan' => $i->kekuatan, 'sediaan' => $i->sediaan, 'satuan_terkecil' => $i->satuan_terkecil],
                    'dosis' => $i->dosis, 'frekuensi_per_hari' => (int) $i->frekuensi_per_hari, 'jumlah_per_pakai' => (float) $i->jumlah_per_pakai,
                    'hari' => (int) $i->hari, 'jumlah' => (float) $i->jumlah, 'rute' => $i->rute, 'aturan_pakai' => $i->aturan_pakai, 'catatan' => $i->catatan,
                ])->values()->all(),
                'peringatan' => $peringatan,
                'antrean' => $a === null ? null : ['id' => $a->id, 'nomor_tampil' => $a->nomor_tampil, 'status' => $a->status],
                'alasan_batal' => $r->alasan_batal, 'dibatalkan_oleh' => $r->dibatalkan_oleh === null ? null : ($pelaku[$r->dibatalkan_oleh] ?? null),
                'dibatalkan_pada' => $r->dibatalkan_pada?->toIso8601String(), 'versi' => (string) $r->revisi,
                'dapat' => [
                    'ubah' => $r->status === 'draf' && $pemilik && $boleh('input', $k->unit_organisasi_id),
                    'sahkan' => $r->status === 'draf' && $dokterJadwal && $boleh('sahkan', $k->unit_organisasi_id),
                    'batal' => $r->status !== 'batal' && ($r->status === 'sah' ? $dokterJadwal : $pemilik) && $boleh('batal', $k->unit_organisasi_id),
                ],
            ];
        })->values()->all();
    }

    private function satu(Resep $r, int $status = 200): JsonResponse
    {
        return (new JsonResponse(['data' => $this->bentuk(collect([$r]), request()->user())[0]], $status))
            ->setEtag((string) $r->revisi)->setPrivate()->header('Cache-Control', 'no-store, private');
    }
}
