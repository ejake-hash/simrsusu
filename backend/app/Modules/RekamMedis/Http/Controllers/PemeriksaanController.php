<?php

namespace App\Modules\RekamMedis\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Application\AksesDaruratAktif;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Http\Resources\KunjunganResource;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\RekamMedis\Application\AksesCatatan;
use App\Modules\RekamMedis\Application\KelolaPemeriksaan;
use App\Modules\RekamMedis\Models\Diagnosis;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Pemeriksaan dokter rawat jalan (tiket 2.15, kontrak v0.25.0): mulai/selesai, diagnosis, ringkasan klinis. Respons `no-store`. */
final class PemeriksaanController
{
    private const IZIN = 'rawat_jalan.pemeriksaan';

    public function __construct(
        private readonly KelolaPemeriksaan $kelola,
        private readonly AksesCatatan $akses,
        private readonly RingkasanPegawai $pegawai,
        private readonly KunjunganResource $kunjungan,
        private readonly AksesDaruratAktif $darurat,
    ) {}

    public function mulai(Request $request, string $kunjungan): JsonResponse
    {
        $k = AsesmenAwalController::kunjungan([self::IZIN.'.input'], $kunjungan);

        return $this->kunjunganSatu($this->kelola->mulai($request->user(), $k->id));
    }

    public function selesai(Request $request, string $kunjungan): JsonResponse
    {
        $k = AsesmenAwalController::kunjungan([self::IZIN.'.sahkan'], $kunjungan);

        return $this->kunjunganSatu($this->kelola->selesai($request->user(), $k->id, IfMatch::versi($request)));
    }

    public function index(Request $request, string $kunjungan): JsonResponse
    {
        $k = AsesmenAwalController::kunjungan([self::IZIN.'.lihat'], $kunjungan);
        $filter = $request->validate(['status' => ['nullable', Rule::in(['aktif', 'batal'])]]);
        $query = Diagnosis::query()->where('kunjungan_id', $k->id)->where('status', $filter['status'] ?? 'aktif');

        return Paginasi::respons($query, $request, 'dibuat_pada', arah: 'asc', bentuk: fn (Collection $baris) => $this->bentuk($baris, $request->user(), $k))
            ->setPrivate()->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, string $kunjungan): JsonResponse
    {
        $k = AsesmenAwalController::kunjungan([self::IZIN.'.input'], $kunjungan);
        $isian = $request->validate(['kode' => ['required', 'string', 'min:1', 'max:20'], 'jenis' => ['required', Rule::in(['utama', 'sekunder'])]]);

        return $this->satu($this->kelola->catat($request->user(), $k->id, $isian['kode'], $isian['jenis']), 201);
    }

    public function update(Request $request, string $diagnosis): JsonResponse
    {
        Gate::authorize(self::IZIN.'.perbaiki');
        $unit = DB::table('klinis.diagnosis AS d')->join('kunjungan.kunjungan AS k', 'k.id', '=', 'd.kunjungan_id')
            ->join('inti.poliklinik AS p', 'p.id', '=', 'k.poli_id')->where('d.id', $diagnosis)->value('p.unit_organisasi_id')
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Diagnosis tidak ditemukan.');
        Gate::authorize(self::IZIN.'.perbaiki', new Cakupan('unit', (string) $unit));
        $isian = $request->validate([
            'jenis' => ['required_without:status', Rule::in(['utama', 'sekunder']), 'prohibits:status,alasan_batal'],
            'status' => ['required_without:jenis', Rule::in(['batal'])],
            'alasan_batal' => ['required_with:status', 'string', 'min:5', 'max:500'],
        ]);

        return $this->satu($this->kelola->ubah($request->user(), $diagnosis, IfMatch::versi($request), $isian));
    }

    /** Cari kode terminologi aktif dari paket yang dimuat RS (K7); dipakai pemilih diagnosis. Bukan data pasien: tanpa audit. */
    public function kode(Request $request, string $sistem): JsonResponse
    {
        Gate::authorize(self::IZIN.'.lihat');
        $q = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q'];
        KelolaPemeriksaan::pastikanPaket($sistem);
        // Ekspresi sama dengan indeks trigram terminologi_cari_trgm.
        $query = DB::table('inti.terminologi')->where('sistem', $sistem)->where('aktif', true)
            ->whereRaw("(kode || ' ' || tampilan) ilike ?", ['%'.addcslashes($q, '\\%_').'%'])
            ->select(['id', 'sistem', 'kode', 'tampilan', 'kode_induk']);

        return Paginasi::respons($query, $request, 'kode', arah: 'asc');
    }

    public function ringkasan(Request $request, string $pasien): JsonResponse
    {
        $izin = self::IZIN.'.lihat';
        Gate::authorize($izin);
        // RM lama yang digabung ke pasien ini ikut (2.9). Pasien tidak ada = tanpa kunjungan = 403, ada/tidaknya tidak terbaca.
        $id = [$pasien, ...DB::table('pasien.pasien')->where('digabung_ke_id', $pasien)->pluck('id')->all()];
        if (! $this->akses->bolehBacaKunjungan($request->user(), [$izin], ['pasien_id' => $id, 'tanpa_batal' => true])) {
            throw new ApiError(403, 'akses_ditolak', 'Pasien ini di luar cakupan akses Anda.');
        }
        $this->darurat->catatBaca($request, $id, null, 'ringkasan_klinis');
        $dasar = fn () => DB::table('klinis.diagnosis AS d')->join('kunjungan.kunjungan AS k', 'k.id', '=', 'd.kunjungan_id')
            ->join('inti.terminologi AS t', fn ($j) => $j->on('t.sistem', '=', 'd.sistem_icd')->on('t.kode', '=', 'd.kode_icd'))
            ->where('d.status', 'aktif')->where('k.status', '<>', 'batal');
        // Satu baris per kode (terbaru); kunjungan di poli bertanda sensitif (nama netral) tidak menyumbang diagnosis.
        $perKode = $dasar()->join('inti.poliklinik AS p', 'p.id', '=', 'k.poli_id')->whereIn('d.pasien_id', $id)->whereNull('p.nama_netral')
            ->selectRaw('DISTINCT ON (d.kode_icd) d.kode_icd AS kode, t.tampilan, d.jenis, d.kunjungan_id, k.tanggal, d.dibuat_pada')
            ->orderBy('d.kode_icd')->orderByDesc('d.dibuat_pada');
        $diagnosis = DB::query()->fromSub($perKode, 'x')->orderByDesc('dibuat_pada')->limit(20)->get()
            ->map(fn ($r) => ['kode' => $r->kode, 'tampilan' => $r->tampilan, 'jenis' => $r->jenis, 'kunjungan_id' => $r->kunjungan_id, 'tanggal' => $r->tanggal]);

        $kunjungan = DB::table('kunjungan.kunjungan AS k')->join('inti.poliklinik AS p', 'p.id', '=', 'k.poli_id')
            ->whereIn('k.pasien_id', $id)->where('k.status', '<>', 'batal')->orderByDesc('k.tanggal')->orderByDesc('k.dibuat_pada')->limit(5)
            ->get(['k.id', 'k.tanggal', 'k.status', 'k.pegawai_id', 'p.id AS poli_id', 'p.nama AS poli_nama', 'p.nama_netral']);
        $utama = $dasar()->whereIn('d.kunjungan_id', $kunjungan->pluck('id'))->where('d.jenis', 'utama')
            ->get(['d.kunjungan_id', 'd.kode_icd AS kode', 't.tampilan'])->keyBy('kunjungan_id');
        $dokter = $this->pegawai->ringkas($kunjungan->pluck('pegawai_id')->unique()->values());

        return (new JsonResponse(['data' => [
            'pasien_id' => $pasien,
            'diagnosis_aktif' => $diagnosis->values()->all(),
            'kunjungan_terakhir' => $kunjungan->map(fn ($k) => [
                'kunjungan_id' => $k->id, 'tanggal' => $k->tanggal, 'status' => $k->status,
                'poliklinik' => ['id' => $k->poli_id, 'nama' => $k->nama_netral ?? $k->poli_nama],
                'dokter' => $dokter[$k->pegawai_id] ?? null,
                'diagnosis_utama' => $k->nama_netral === null && isset($utama[$k->id]) ? ['kode' => $utama[$k->id]->kode, 'tampilan' => $utama[$k->id]->tampilan] : null,
            ])->values()->all(),
        ]]))->setPrivate()->header('Cache-Control', 'no-store, private');
    }

    /**
     * @param  Collection<int, Diagnosis>  $baris
     * @return list<array<string, mixed>>
     */
    private function bentuk(Collection $baris, Pengguna $pengguna, ?object $kunjungan = null): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $tampilan = DB::table('inti.terminologi')->where('sistem', 'icd10')->whereIn('kode', $baris->pluck('kode_icd')->unique()->values())->pluck('tampilan', 'kode');
        $akun = Pengguna::query()->whereIn('id', $baris->pluck('dibuat_oleh')->merge($baris->pluck('dibatalkan_oleh'))->filter()->unique()->values())
            ->get(['id', 'pegawai_id', 'nama_pengguna']);
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id')->filter()->values()->all());
        $pelaku = $akun->mapWithKeys(fn (Pengguna $a) => [$a->id => ['id' => $a->id, 'nama' => $nama[$a->pegawai_id] ?? $a->nama_pengguna]]);
        $kunjungan ??= DB::table('kunjungan.kunjungan AS k')->join('inti.poliklinik AS p', 'p.id', '=', 'k.poli_id')
            ->where('k.id', $baris->first()->kunjungan_id)->first(['k.status', 'p.unit_organisasi_id']);
        $bolehUbah = $kunjungan->status === 'dilayani'
            && Gate::forUser($pengguna)->allows(self::IZIN.'.perbaiki', new Cakupan('unit', (string) $kunjungan->unit_organisasi_id));

        return $baris->map(fn (Diagnosis $d) => [
            'id' => $d->id, 'kunjungan_id' => $d->kunjungan_id, 'sistem' => $d->sistem_icd, 'kode' => $d->kode_icd,
            'tampilan' => $tampilan[$d->kode_icd] ?? $d->kode_icd, 'jenis' => $d->jenis, 'sumber' => $d->sumber, 'status' => $d->status,
            'dicatat_oleh' => $pelaku[$d->dibuat_oleh] ?? null, 'dicatat_pada' => $d->dibuat_pada->toIso8601String(),
            'alasan_batal' => $d->alasan_batal, 'dibatalkan_oleh' => $d->dibatalkan_oleh === null ? null : ($pelaku[$d->dibatalkan_oleh] ?? null),
            'dibatalkan_pada' => $d->dibatalkan_pada?->toIso8601String(),
            'dapat' => ['ubah' => $bolehUbah && $d->status === 'aktif' && $d->dibuat_oleh === $pengguna->id],
            'versi' => (string) $d->revisi, 'diubah_pada' => $d->diubah_pada->toIso8601String(),
        ])->values()->all();
    }

    private function satu(Diagnosis $d, int $status = 200): JsonResponse
    {
        return (new JsonResponse(['data' => $this->bentuk(collect([$d]), request()->user())[0]], $status))
            ->setEtag((string) $d->revisi)->setPrivate()->header('Cache-Control', 'no-store, private');
    }

    private function kunjunganSatu(Kunjungan $k): JsonResponse
    {
        $k->refresh();

        return (new JsonResponse(['data' => $this->kunjungan->satu($k)]))->setEtag((string) $k->revisi)->header('Cache-Control', 'no-store');
    }
}
