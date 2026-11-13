<?php

namespace App\Modules\RawatInap\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Notifikasi\Application\KotakKerja;
use App\Modules\Pendaftaran\Application\KunciKunjungan;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\Ttd\Application\Penandatangan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Resume medis pulang rawat inap (tiket 2.40, kontrak v0.39.0; FR-05-02, FR-05-04, keputusan pemilik 13 Nov 2026).
 * - Susun (POST, izin rawat_inap.pemulangan.input di cakupan unit bangsal, bersyarat SIP/SPK): potret sumber yang ada —
 *   CPPT SOAP sah episode tanpa label sensitivitas, diagnosis aktif kunjungan dalam episode, resep sah + butir kunjungan
 *   dalam episode, alergi aktif pasien — plus isian DPJP. Selama draf, POST ulang memotret ulang sumber dan memperbarui isian
 *   yang dikirim. Tindakan belum tersedia (ditandai).
 * - Sahkan (izin rawat_inap.pemulangan.sahkan, IZIN_KLINIS): hanya DPJP kunjungan (`kunjungan.pegawai_id`), TTE T1 pemilik
 *   akun (pola KelolaResep: buka kunci di luar transaksi, tanda tangan di dalam, sodium_memzero, audit paling akhir).
 *   Sah = final (adendum menyusul); boleh sesudah pasien pulang. Tugas RESUME_BELUM_TTE_48J diselesaikan.
 * Isi klinis resume tidak pernah ke log/audit.
 */
final class KelolaResumeMedis
{
    public const IZIN_LIHAT = 'rawat_inap.pemulangan.lihat';

    /** Klinis (SIP/SPK): menyusun resume; kode yang sama = izin sel matriks pulang (PulangkanPasien). */
    public const IZIN_INPUT = 'rawat_inap.pemulangan.input';

    public const IZIN_SAHKAN = 'rawat_inap.pemulangan.sahkan';

    /** Batas potret per sumber (bukan daftar berkursor); lebih → `terpotong`. */
    private const BATAS_SUMBER = 500;

    private const ISIAN = ['ringkasan_perjalanan', 'kondisi_pulang', 'instruksi_pulang', 'obat_pulang', 'kontrol'];

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly Penandatangan $ttd,
        private readonly KotakKerja $kotakKerja,
        private readonly NamaPelaku $pelaku,
    ) {}

    /** POST susun: izin input di cakupan mana pun (403) → kunjungan ada (404) → cakupan unit bangsal (403). */
    public function izinkanSusun(Pengguna $pengguna, string $kunjunganId): void
    {
        Gate::forUser($pengguna)->authorize(self::IZIN_INPUT);
        $k = KelolaRencanaPulang::kunjungan($kunjunganId);
        Gate::forUser($pengguna)->authorize(self::IZIN_INPUT, KelolaRencanaPulang::cakupan($k->unit_id));
    }

    /**
     * @param  array<string, string|null>  $isian  bagian isian DPJP yang dikirim (kunci lain diabaikan)
     * @return array{0: string, 1: bool} id resume, true = baru
     */
    public function susun(Pengguna $pengguna, string $kunjunganId, array $isian): array
    {
        return DB::transaction(function () use ($pengguna, $kunjunganId, $isian) {
            $k = KunciKunjungan::pasienLaluKunjungan($kunjunganId) ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
            Gate::forUser($pengguna)->authorize(self::IZIN_INPUT, KelolaRencanaPulang::cakupan($k->unit_id));
            if ($k->jenis_kunjungan !== Kunjungan::RAWAT_INAP || $k->status === 'batal') {
                throw new ApiError(409, 'status_tidak_sesuai', 'Resume medis hanya untuk kunjungan rawat inap.',
                    [['jenis_kunjungan' => $k->jenis_kunjungan, 'status' => $k->status]]);
            }
            $lama = DB::table('klinis.resume_medis')->where('kunjungan_id', $k->id)->lockForUpdate()->first(['id', 'status', 'ringkasan']);
            if ($lama !== null && $lama->status === 'sah') {
                throw new ApiError(409, 'sudah_disahkan', 'Resume medis ini sudah disahkan dan tidak bisa diubah.');
            }
            $ringkasanLama = $lama === null ? null : json_decode((string) $lama->ringkasan, true);
            $isianLama = is_array($ringkasanLama) && is_array($ringkasanLama['isian_dpjp'] ?? null) ? $ringkasanLama['isian_dpjp'] : [];
            $isianBaru = [];
            foreach (self::ISIAN as $kunci) {
                $nilai = array_key_exists($kunci, $isian) ? $isian[$kunci] : ($isianLama[$kunci] ?? null);
                $isianBaru[$kunci] = $nilai === null || trim((string) $nilai) === '' ? null : trim((string) $nilai);
            }
            $ringkasan = json_encode(['sumber' => self::potret($k), 'isian_dpjp' => $isianBaru]);
            $jam = now()->utc()->format('Y-m-d H:i:s.uP');
            $nilai = ['ringkasan' => $ringkasan, 'disusun_oleh' => $pengguna->id, 'disusun_pada' => $jam, 'diubah_oleh' => $pengguna->id, 'diubah_pada' => $jam];
            if ($lama === null) {
                $id = (string) Str::uuid7();
                DB::table('klinis.resume_medis')->insert([...$nilai, 'id' => $id, 'kunjungan_id' => $k->id, 'pasien_id' => $k->pasien_id,
                    'status' => 'draf', 'dibuat_oleh' => $pengguna->id, 'dibuat_pada' => $jam]);
            } else {
                $id = (string) $lama->id;
                DB::table('klinis.resume_medis')->where('id', $id)->update([...$nilai, 'revisi' => DB::raw('revisi + 1')]);
            }
            $this->audit->catat($lama === null ? 'klinis.resume_medis_disusun' : 'klinis.resume_medis_disusun_ulang', $pengguna->id,
                'klinis.resume_medis', $id, ['kunjungan_id' => $k->id]);

            return [$id, $lama === null];
        });
    }

    /** POST sahkan: izin sahkan di cakupan mana pun (403) → resume ada (404) → cakupan unit bangsal (403). */
    public function izinkanSahkan(Pengguna $pengguna, string $resumeId): string
    {
        Gate::forUser($pengguna)->authorize(self::IZIN_SAHKAN);
        $kunjunganId = (Str::isUuid($resumeId) ? DB::table('klinis.resume_medis')->where('id', $resumeId)->value('kunjungan_id') : null)
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Resume medis tidak ditemukan.');
        $k = KelolaRencanaPulang::kunjungan((string) $kunjunganId);
        Gate::forUser($pengguna)->authorize(self::IZIN_SAHKAN, KelolaRencanaPulang::cakupan($k->unit_id));

        return (string) $kunjunganId;
    }

    public function sahkan(Request $request, Pengguna $pengguna, string $resumeId, string $versi, ?string $pin): void
    {
        $r = DB::table('klinis.resume_medis')->where('id', $resumeId)->first(['id', 'kunjungan_id', 'status', 'revisi'])
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Resume medis tidak ditemukan.');
        if (! self::dpjp($pengguna, (string) $r->kunjungan_id)) {
            throw new ApiError(403, 'bukan_pemutus', 'Resume medis hanya bisa disahkan DPJP pasien ini.');
        }
        self::pastikanDraf((string) $r->status);
        if (IfMatch::revisi($versi) !== (int) $r->revisi) {
            throw new ApiError(412, 'versi_berubah', 'Resume medis ini disusun ulang sejak Anda membukanya. Muat ulang untuk melihat isi terbaru.');
        }
        // Di luar transaksi: hitungan PIN salah dan penguncian tersimpan walau pengesahan batal.
        $kunci = [...$this->ttd->buka($request, $pengguna, $pin), $pin === null];

        try {
            DB::transaction(function () use ($pengguna, $r, $versi, $kunci) {
                // Urutan kunci sama dengan pemulangan (pasien → kunjungan): tugas resume yang dibuat saat pulang tidak terlewat.
                KunciKunjungan::pasienLaluKunjungan((string) $r->kunjungan_id);
                $baris = DB::table('klinis.resume_medis')->where('id', $r->id)->lockForUpdate()->first(['id', 'status', 'revisi']);
                self::pastikanDraf((string) $baris?->status);
                if (IfMatch::revisi($versi) !== (int) $baris?->revisi) {
                    throw new ApiError(412, 'versi_berubah', 'Resume medis ini disusun ulang sejak Anda membukanya. Muat ulang untuk melihat isi terbaru.');
                }
                $jam = now()->utc()->format('Y-m-d H:i:s.uP');
                DB::table('klinis.resume_medis')->where('id', $r->id)->update([
                    'status' => 'sah', 'disahkan_oleh' => $pengguna->id, 'disahkan_pada' => $jam, 'diubah_oleh' => $pengguna->id, 'diubah_pada' => $jam,
                    'revisi' => DB::raw('revisi + 1'),
                ]);
                $tandaTangan = $this->ttd->tandatanganiTerbuka($pengguna, [['jenis' => 'resume_medis', 'id' => (string) $r->id]], $kunci[0], $kunci[1], $kunci[2])[0]['tanda_tangan']->id;
                DB::table('klinis.resume_medis')->where('id', $r->id)->update(['tanda_tangan_id' => $tandaTangan]);
                $this->kotakKerja->selesaikan(PulangkanPasien::TUGAS_RESUME, (string) $r->kunjungan_id, 'Resume medis disahkan');
                $this->audit->catat('klinis.resume_medis_disahkan', $pengguna->id, 'klinis.resume_medis', (string) $r->id,
                    ['kunjungan_id' => $r->kunjungan_id, 'tanda_tangan_id' => $tandaTangan]);
            });
        } finally {
            sodium_memzero($kunci[1]);
        }
    }

    /**
     * Bentuk kontrak `ResumeMedis`; null bila belum disusun.
     *
     * @return array<string, mixed>|null
     */
    public function baca(string $kunjunganId): ?array
    {
        $r = DB::table('klinis.resume_medis as r')->join('kunjungan.kunjungan as k', 'k.id', '=', 'r.kunjungan_id')
            ->join('pasien.pasien as p', 'p.id', '=', 'r.pasien_id')->where('r.kunjungan_id', $kunjunganId)
            ->first(['r.*', 'k.pegawai_id as dpjp_pegawai_id', 'p.no_rm', 'p.nama', 'p.jenis_kelamin', 'p.tanggal_lahir']);
        if ($r === null) {
            return null;
        }
        $ringkasan = (array) json_decode((string) $r->ringkasan, true);
        $pelaku = $this->pelaku->untuk([(string) $r->disusun_oleh, $r->disahkan_oleh === null ? null : (string) $r->disahkan_oleh]);
        $dpjp = $r->dpjp_pegawai_id === null ? null : DB::table('sdm.pegawai')->where('id', $r->dpjp_pegawai_id)->first(['id', 'nama']);
        $waktu = fn (mixed $v) => $v === null ? null : Carbon::parse((string) $v)->toIso8601String();

        return [
            'id' => (string) $r->id,
            'kunjungan_id' => (string) $r->kunjungan_id,
            'status' => (string) $r->status,
            'pasien' => KelolaRencanaPulang::pasien($r),
            'dpjp' => $dpjp === null ? null : ['id' => (string) $dpjp->id, 'nama' => (string) $dpjp->nama],
            'sumber' => $ringkasan['sumber'] ?? null,
            'isian_dpjp' => $ringkasan['isian_dpjp'] ?? null,
            'disusun_oleh' => $pelaku[(string) $r->disusun_oleh] ?? null,
            'disusun_pada' => $waktu($r->disusun_pada),
            'disahkan_oleh' => $r->disahkan_oleh === null ? null : ($pelaku[(string) $r->disahkan_oleh] ?? null),
            'disahkan_pada' => $waktu($r->disahkan_pada),
            'tanda_tangan_id' => $r->tanda_tangan_id === null ? null : (string) $r->tanda_tangan_id,
            'versi' => (string) $r->revisi,
        ];
    }

    /** Akun ini DPJP kunjungan (kunjungan.pegawai_id; 2.29 Asumsi BE #202). */
    public static function dpjp(Pengguna $pengguna, string $kunjunganId): bool
    {
        return ! empty($pengguna->pegawai_id)
            && DB::table('kunjungan.kunjungan')->where('id', $kunjunganId)->value('pegawai_id') === $pengguna->pegawai_id;
    }

    private static function pastikanDraf(string $status): void
    {
        if ($status === 'sah') {
            throw new ApiError(409, 'sudah_disahkan', 'Resume medis ini sudah disahkan.');
        }
    }

    /**
     * Potret sumber resume dari data yang ada saat disusun (FR-05-02). CPPT/diagnosis/resep yang ditulis di kunjungan
     * rawat inap sendiri menyusul (utang backlog 2.40); yang terbaca = semua kunjungan dalam episode.
     *
     * @return array<string, mixed>
     */
    private static function potret(Kunjungan $k): array
    {
        $kunjunganEpisode = DB::table('kunjungan.kunjungan')->where('episode_id', $k->episode_id)->select('id');
        $waktu = fn (mixed $v) => $v === null ? null : Carbon::parse((string) $v)->toIso8601String();

        $cppt = DB::table('klinis.catatan_klinis as c')->leftJoin('akses.pengguna as u', 'u.id', '=', 'c.penulis_id')
            ->leftJoin('sdm.pegawai as pg', 'pg.id', '=', 'u.pegawai_id')
            ->where('c.episode_id', $k->episode_id)->where('c.jenis', 'soap')->where('c.status', 'sah')->whereNull('c.label')
            ->orderBy('c.selesai_pada')->orderBy('c.id')->limit(self::BATAS_SUMBER + 1)
            ->get(['c.id', 'c.kunjungan_id', 'c.profesi', 'c.isi', 'c.selesai_pada', 'u.nama_pengguna', 'pg.nama as nama_pegawai']);
        $berlabel = DB::table('klinis.catatan_klinis')->where('episode_id', $k->episode_id)->where('jenis', 'soap')->where('status', 'sah')
            ->whereNotNull('label')->count();

        $diagnosis = DB::table('klinis.diagnosis as d')->leftJoin('inti.terminologi as t', fn ($j) => $j->on('t.sistem', '=', 'd.sistem_icd')->on('t.kode', '=', 'd.kode_icd'))
            ->whereIn('d.kunjungan_id', $kunjunganEpisode)->where('d.status', 'aktif')
            ->orderBy('d.dibuat_pada')->orderBy('d.id')->limit(self::BATAS_SUMBER + 1)
            ->get(['d.kunjungan_id', 'd.sistem_icd', 'd.kode_icd', 'd.jenis', 't.tampilan']);

        $resep = DB::table('farmasi.resep')->whereIn('kunjungan_id', $kunjunganEpisode)->where('status', 'sah')
            ->orderBy('masuk_pada')->orderBy('id')->limit(self::BATAS_SUMBER + 1)->get(['id', 'kunjungan_id', 'no_resep', 'masuk_pada']);
        $butir = DB::table('farmasi.resep_item as i')->join('logistik.barang as b', 'b.id', '=', 'i.barang_id')
            ->whereIn('i.resep_id', $resep->pluck('id')->all())->whereNull('i.diganti_pada')->orderBy('i.urutan')
            ->get(['i.resep_id', 'b.id as barang_id', 'b.kode', 'b.nama', 'i.dosis', 'i.frekuensi_per_hari', 'i.jumlah_per_pakai', 'i.hari', 'i.jumlah', 'i.rute', 'i.aturan_pakai'])
            ->groupBy('resep_id');

        $alergi = DB::table('klinis.alergi')->where('pasien_id', $k->pasien_id)->where('status', 'aktif')
            ->orderBy('dibuat_pada')->orderBy('id')->limit(self::BATAS_SUMBER + 1)->get(['zat', 'jenis', 'reaksi', 'tingkat']);

        $isi = fn (mixed $json) => (array) (is_string($json) ? json_decode($json, true) : $json);

        return [
            'cppt' => $cppt->take(self::BATAS_SUMBER)->map(fn ($c) => [
                'id' => (string) $c->id, 'kunjungan_id' => (string) $c->kunjungan_id, 'profesi' => (string) $c->profesi,
                'penulis' => (string) ($c->nama_pegawai ?? $c->nama_pengguna ?? ''), 'disahkan_pada' => $waktu($c->selesai_pada),
                'subjektif' => $isi($c->isi)['subjektif'] ?? null, 'objektif' => $isi($c->isi)['objektif'] ?? null,
                'asesmen' => $isi($c->isi)['asesmen'] ?? null, 'plan' => $isi($c->isi)['plan'] ?? null,
            ])->values()->all(),
            'cppt_berlabel' => $berlabel,
            'diagnosis' => $diagnosis->take(self::BATAS_SUMBER)->map(fn ($d) => [
                'kunjungan_id' => (string) $d->kunjungan_id, 'sistem' => (string) $d->sistem_icd, 'kode' => (string) $d->kode_icd,
                'tampilan' => $d->tampilan === null ? null : (string) $d->tampilan, 'jenis' => (string) $d->jenis,
            ])->values()->all(),
            'resep' => $resep->take(self::BATAS_SUMBER)->map(fn ($r) => [
                'id' => (string) $r->id, 'kunjungan_id' => (string) $r->kunjungan_id, 'no_resep' => (string) $r->no_resep,
                'disahkan_pada' => $waktu($r->masuk_pada),
                'butir' => collect($butir->get($r->id, []))->map(fn ($b) => [
                    'barang' => ['id' => (string) $b->barang_id, 'kode' => (string) $b->kode, 'nama' => (string) $b->nama],
                    'dosis' => $b->dosis, 'frekuensi_per_hari' => (int) $b->frekuensi_per_hari, 'jumlah_per_pakai' => (float) $b->jumlah_per_pakai,
                    'hari' => (int) $b->hari, 'jumlah' => (float) $b->jumlah, 'rute' => $b->rute, 'aturan_pakai' => $b->aturan_pakai,
                ])->values()->all(),
            ])->values()->all(),
            'alergi' => $alergi->take(self::BATAS_SUMBER)->map(fn ($a) => [
                'zat' => (string) $a->zat, 'jenis' => (string) $a->jenis, 'reaksi' => $a->reaksi, 'tingkat' => $a->tingkat,
            ])->values()->all(),
            'tindakan' => ['tersedia' => false],
            'terpotong' => $cppt->count() > self::BATAS_SUMBER || $diagnosis->count() > self::BATAS_SUMBER
                || $resep->count() > self::BATAS_SUMBER || $alergi->count() > self::BATAS_SUMBER,
            'dipotret_pada' => now()->toIso8601String(),
        ];
    }
}
