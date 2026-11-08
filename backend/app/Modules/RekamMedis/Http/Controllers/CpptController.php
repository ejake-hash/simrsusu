<?php

namespace App\Modules\RekamMedis\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Application\AksesDaruratAktif;
use App\Modules\Akses\Application\PemeriksaLabel;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\LabelSensitivitas;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\RekamMedis\Application\AksesCatatan;
use App\Modules\RekamMedis\Application\KelolaCppt;
use App\Modules\RekamMedis\Models\CatatanKlinis;
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
 * CPPT episode (tiket 2.14, kontrak v0.24.0). Linimasa lintas pos per episode atau pasien: entri sah + draf milik akun ini,
 * entri berlabel yang tidak lolos lapis 4 → CpptTerbatas (dikeluarkan bila ada filter profesi/tatanan). Respons `no-store`.
 */
final class CpptController
{
    public function __construct(
        private readonly KelolaCppt $kelola,
        private readonly AksesCatatan $akses,
        private readonly PemeriksaLabel $label,
        private readonly RingkasanPegawai $pegawai,
        private readonly AksesDaruratAktif $darurat,
    ) {}

    public function episode(Request $request, string $episode): JsonResponse
    {
        self::izinSalahSatu(AksesCatatan::LIHAT_CPPT);
        $e = DB::table('kunjungan.episode')->where('id', $episode)->first(['id', 'pasien_id'])
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Episode tidak ditemukan.');
        $this->pastikanCakupan(['episode_id' => $e->id]);
        $this->darurat->catatBaca($request, [(string) $e->pasien_id], null, 'cppt');

        return $this->linimasa($request, fn (Builder $q) => $q->where('episode_id', $e->id), [(string) $e->pasien_id]);
    }

    public function pasien(Request $request, string $pasien): JsonResponse
    {
        self::izinSalahSatu(AksesCatatan::LIHAT_CPPT);
        abort_unless(DB::table('pasien.pasien')->where('id', $pasien)->exists(), 404);
        // RM lama yang digabung ke pasien ini ikut (2.9).
        $id = [$pasien, ...DB::table('pasien.pasien')->where('digabung_ke_id', $pasien)->pluck('id')->all()];
        $this->pastikanCakupan(['pasien_id' => $id]);
        $this->darurat->catatBaca($request, $id, null, 'cppt');

        return $this->linimasa($request, fn (Builder $q) => $q->whereIn('pasien_id', $id), $id);
    }

    public function store(Request $request, string $kunjungan): JsonResponse
    {
        $k = AsesmenAwalController::kunjungan(['rawat_jalan.cppt.input'], $kunjungan);
        $isian = $request->validate(self::aturanIsi());

        return $this->satu($this->kelola->buat($request->user(), $k, $k->unit_organisasi_id, $isian), 201);
    }

    public function show(Request $request, string $cppt): JsonResponse
    {
        self::izinSalahSatu(AksesCatatan::LIHAT_CPPT);
        $c = CatatanKlinis::query()->whereKey($cppt)->where('jenis', 'soap')->first();
        $pengguna = $request->user();
        if ($c === null || ($c->status === 'draf' && $c->penulis_id !== $pengguna->id)) {
            throw new ApiError(404, 'tidak_ditemukan', 'CPPT tidak ditemukan.');
        }
        $this->pastikanCakupan(['episode_id' => $c->episode_id]);
        $this->darurat->catatBaca($request, [$c->pasien_id], $c->kunjungan_id, 'cppt');
        if (! $this->akses->terbuka($pengguna, $c)) {
            return (new JsonResponse(['data' => PemeriksaLabel::TERBATAS]))->setPrivate()->header('Cache-Control', 'no-store, private');
        }

        return $this->satu($c);
    }

    public function update(Request $request, string $cppt): JsonResponse
    {
        $c = self::milik('perbaiki', $cppt);
        $isian = $request->validate([...self::aturanIsi(), 'alasan_perubahan' => ['sometimes', 'string', 'min:5', 'max:500'],
            'pin' => ['sometimes', 'nullable', 'string', 'max:20'], 'pernyataan_disetujui' => ['sometimes', 'accepted']]);
        $isian['pernyataan_disetujui'] = isset($isian['pernyataan_disetujui']) ? true : null;

        return $this->satu($this->kelola->ubah($request, $request->user(), $c, IfMatch::versi($request), $isian));
    }

    public function sahkan(Request $request, string $cppt): JsonResponse
    {
        $c = self::milik('sahkan', $cppt);
        $isian = $request->validate(['pin' => ['nullable', 'string', 'max:20'], 'pernyataan_disetujui' => ['required', 'accepted']]);

        return $this->satu($this->kelola->sahkan($request, $request->user(), $c, IfMatch::versi($request), $isian['pin'] ?? null));
    }

    public function adendum(Request $request, string $cppt): JsonResponse
    {
        $c = self::milik('input', $cppt);
        $isian = $request->validate(['isi' => ['required', 'string', 'min:1', 'max:4000'], 'alasan' => ['required', 'string', 'min:5', 'max:500'],
            'pin' => ['nullable', 'string', 'max:20'], 'pernyataan_disetujui' => ['required', 'accepted']]);

        return $this->satu($this->kelola->adendum($request, $request->user(), $c, IfMatch::versi($request), $isian['isi'], $isian['alasan'], $isian['pin'] ?? null), 201);
    }

    /** @return array<string, list<mixed>> */
    private static function aturanIsi(): array
    {
        $teks = ['sometimes', 'nullable', 'string', 'max:4000'];

        return [
            'subjektif' => $teks, 'objektif' => $teks, 'asesmen' => $teks, 'plan' => $teks,
            'label' => ['sometimes', 'nullable', 'string', Rule::exists(LabelSensitivitas::class, 'kode')->where('aktif', true)],
        ];
    }

    /**
     * @param  callable(Builder<CatatanKlinis>): mixed  $sumber
     * @param  list<string>  $pasienId
     */
    private function linimasa(Request $request, callable $sumber, array $pasienId): JsonResponse
    {
        $filter = $request->validate(['profesi' => ['nullable', Rule::in(['dokter', 'perawat', 'bidan', 'apoteker'])],
            'tatanan' => ['nullable', Rule::in(['rj', 'igd', 'ri', 'ok', 'icu', 'hd'])]]);
        $pengguna = $request->user();
        // Lapis 4 dihitung sekali per (pasien, label) halaman ini: label sedikit, pasien satu (atau RM gabungan).
        $kodeLabel = DB::table('akses.label_sensitivitas')->pluck('kode')->all();
        $boleh = [];
        foreach ($pasienId as $p) {
            $boleh[$p] = array_values(array_filter($kodeLabel, fn ($l) => $this->label->boleh($pengguna, $l, $p)));
        }
        $query = CatatanKlinis::query()->where('jenis', 'soap')->where($sumber)
            ->where(fn ($q) => $q->where('status', 'sah')->orWhere('penulis_id', $pengguna->id))
            ->when($filter['profesi'] ?? null, fn ($q, $v) => $q->where('profesi', $v))
            ->when($filter['tatanan'] ?? null, fn ($q, $v) => $q->where('tatanan', $v))
            ->when(($filter['profesi'] ?? null) !== null || ($filter['tatanan'] ?? null) !== null, fn ($q) => $q->where(function ($w) use ($pengguna, $boleh) {
                $w->whereNull('label')->orWhere('penulis_id', $pengguna->id);
                foreach ($boleh as $p => $label) {
                    $w->orWhere(fn ($x) => $x->where('pasien_id', $p)->whereIn('label', $label));
                }
            }));

        return Paginasi::respons($query, $request, 'dibuat_pada', bentuk: function (Collection $baris) use ($pengguna, $boleh) {
            $terbuka = $baris->filter(fn (CatatanKlinis $c) => $c->label === null || $c->penulis_id === $pengguna->id || in_array($c->label, $boleh[$c->pasien_id] ?? [], true));
            $bentuk = $this->bentuk($terbuka, $pengguna);

            return $baris->map(fn (CatatanKlinis $c) => $bentuk[$c->id]
                ?? ['id' => $c->id, 'terbatas' => true, 'dibuat_pada' => $c->dibuat_pada->toIso8601String()])->values()->all();
        })->setPrivate()->header('Cache-Control', 'no-store, private');
    }

    /**
     * @param  Collection<int, CatatanKlinis>  $baris
     * @return array<string, array<string, mixed>>
     */
    private function bentuk(Collection $baris, Pengguna $pengguna): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $adendum = DB::table('klinis.catatan_klinis_adendum')->whereIn('catatan_klinis_id', $baris->pluck('id'))->orderBy('disahkan_pada')->orderBy('id')
            ->limit(50 * $baris->count())->get()->groupBy('catatan_klinis_id');
        $akun = Pengguna::query()->whereIn('id', $baris->pluck('penulis_id')->merge($adendum->flatten(1)->pluck('penulis_id'))->unique()->values())
            ->get(['id', 'pegawai_id', 'nama_pengguna']);
        $namaPegawai = $this->pegawai->nama($akun->pluck('pegawai_id')->filter()->values()->all());
        $pelaku = $akun->mapWithKeys(fn (Pengguna $a) => [$a->id => ['id' => $a->id, 'nama' => $namaPegawai[$a->pegawai_id] ?? $a->nama_pengguna]]);
        $unit = DB::table('inti.unit_organisasi')->whereIn('id', $baris->pluck('unit_id')->unique()->values())->get(['id', 'kode', 'nama'])->keyBy('id');
        $gate = Gate::forUser($pengguna);

        $hasil = [];
        foreach ($baris as $c) {
            $u = $unit[$c->unit_id];
            $milik = $c->penulis_id === $pengguna->id;
            $cakupan = new Cakupan('unit', $c->unit_id);
            $hasil[$c->id] = [
                'id' => $c->id, 'episode_id' => $c->episode_id, 'kunjungan_id' => $c->kunjungan_id,
                'unit' => ['id' => $u->id, 'kode' => $u->kode, 'nama' => $u->nama], 'tatanan' => $c->tatanan, 'profesi' => $c->profesi, 'jenis' => $c->jenis,
                'isi' => array_merge(array_fill_keys(KelolaCppt::SOAP, null), array_intersect_key($c->isi, array_flip(KelolaCppt::SOAP))),
                'label' => $c->label, 'status' => $c->status, 'penulis' => $pelaku[$c->penulis_id] ?? null,
                'dibuat_pada' => $c->dibuat_pada->toIso8601String(), 'disahkan_pada' => $c->selesai_pada?->toIso8601String(),
                'batas_perbaikan_pada' => $c->batas_perbaikan_pada?->toIso8601String(), 'jumlah_versi' => $c->jumlah_versi,
                'adendum' => ($adendum[$c->id] ?? collect())->map(fn ($a) => [
                    'id' => $a->id, 'penulis' => $pelaku[$a->penulis_id] ?? null, 'isi' => $a->isi, 'alasan' => $a->alasan,
                    'disahkan_pada' => Carbon::parse((string) $a->disahkan_pada)->toIso8601String(),
                ])->values()->all(),
                'dapat' => [
                    'ubah' => $milik && KelolaCppt::masihBisaDiperbaiki($c) && $gate->allows('rawat_jalan.cppt.perbaiki', $cakupan),
                    'sahkan' => $milik && $c->status === 'draf' && $gate->allows('rawat_jalan.cppt.sahkan', $cakupan),
                    'adendum' => $milik && $c->status === 'sah' && ! KelolaCppt::masihBisaDiperbaiki($c) && $gate->allows('rawat_jalan.cppt.input', $cakupan),
                ],
                'versi' => (string) $c->revisi, 'diubah_pada' => $c->diubah_pada->toIso8601String(),
            ];
        }

        return $hasil;
    }

    private function satu(CatatanKlinis $c, int $status = 200): JsonResponse
    {
        $data = $this->bentuk(collect([$c]), request()->user())[$c->id];

        return (new JsonResponse(['data' => $data], $status))->setEtag((string) $c->revisi)->setPrivate()->header('Cache-Control', 'no-store, private');
    }

    /** @param  array{episode_id?: string, pasien_id?: list<string>}  $filter */
    private function pastikanCakupan(array $filter): void
    {
        if (! $this->akses->bolehBacaKunjungan(request()->user(), AksesCatatan::LIHAT_CPPT, $filter)) {
            throw new ApiError(403, 'akses_ditolak', 'Pasien ini di luar cakupan akses Anda.');
        }
    }

    /** @param  list<string>  $izin */
    private static function izinSalahSatu(array $izin): void
    {
        if (! array_any($izin, fn ($i) => Gate::allows($i))) {
            throw new ApiError(403, 'akses_ditolak', 'Anda tidak memiliki akses ke halaman ini.');
        }
    }

    /** Entri untuk ditulis: izin aksi dulu (tanpa izin, ada tidaknya id tidak terbaca), lalu cakupan unit entri. */
    private static function milik(string $aksi, string $id): CatatanKlinis
    {
        Gate::authorize("rawat_jalan.cppt.$aksi");
        $c = CatatanKlinis::query()->whereKey($id)->where('jenis', 'soap')->first()
            ?? throw new ApiError(404, 'tidak_ditemukan', 'CPPT tidak ditemukan.');
        Gate::authorize("rawat_jalan.cppt.$aksi", new Cakupan('unit', $c->unit_id));

        return $c;
    }
}
