<?php

namespace App\Modules\RawatInap\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\SuntinganBerversi;
use App\Core\Instalasi\StatusInstalasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\Peran;
use App\Modules\MasterData\Models\AturanPerpindahan;
use App\Modules\Notifikasi\Application\KotakKerja;
use App\Modules\Pendaftaran\Application\KelolaPasien;
use App\Modules\Pendaftaran\Application\KunciKunjungan;
use App\Modules\Pendaftaran\Events\DisposisiDicatat;
use App\Modules\Pendaftaran\Events\DisposisiRawatInap;
use App\Modules\Pendaftaran\Events\KunjunganDibatalkan;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\RawatInap\Domain\StatusTempatTidur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Satu-satunya pembuat permintaan kamar (tiket 2.28; FR-08-03, FR-03-07, PRD 7.00.2 baris "Rawat inap"): SPRI dokter
 * poli (POST /kunjungan/{id}/spri) dan disposisi IGD → rawat inap (kejadian DisposisiRawatInap, 2.26) sama-sama lewat
 * buat(): SPRI internal (kunjungan.surat_kontrol) + permintaan berstatus `menunggu`. Permintaan IGD membuka tugas
 * PERMINTAAN_KAMAR_MENUNGGU (hanya bila jenisnya diaktifkan RS) yang selesai saat alokasi dan batal bersama permintaan.
 * Permintaan aktif ikut batal lewat kejadian, bukan teks (tinjauan #194 butir 1): disposisi IGD yang menutup kunjungan
 * (pulang, APS, rujuk, meninggal, DOA) dan pembatalan kunjungan. Pesanan TT yang batal mengembalikan TT ke kosong siap.
 * Urutan kunci: pasien → kunjungan (KunciKunjungan) → permintaan → TT, sama dengan alokasi (permintaan → TT).
 */
final class PermintaanKamar
{
    public const JENIS_TUGAS = 'PERMINTAAN_KAMAR_MENUNGGU';

    /** Status permintaan yang masih di antrean. */
    public const AKTIF = ['menunggu', 'dialokasikan'];

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly StatusInstalasi $instalasi,
        private readonly KotakKerja $kotakKerja,
        private readonly UbahStatusTempatTidur $statusTt,
    ) {}

    /**
     * Izin SPRI sebelum isian dibaca: sel matriks poliklinik → rawat_inap, cakupan unit poli, termasuk lapis 5 (SIP/SPK).
     * 404 kunjungan tidak ada; 409 kunjungan IGD (rawat inap IGD lewat disposisi); 422 sel matriks nonaktif; 403.
     */
    public function izinkanSpri(Pengguna $dokter, string $kunjunganId): void
    {
        $k = Kunjungan::query()->find($kunjunganId) ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
        $this->periksaIzinSpri($dokter, $k);
    }

    /**
     * @param  array{alasan: string, kelas_rawat_id: string, bangsal_id?: string|null, tanggal_rencana?: string|null}  $isi
     * @return string id permintaan
     */
    public function spri(Pengguna $dokter, string $kunjunganId, array $isi): string
    {
        KelolaPasien::pastikanSiapMelayani($this->instalasi);
        $tanggal = $isi['tanggal_rencana'] ?? SuntinganBerversi::hariIni();
        if ($tanggal < SuntinganBerversi::hariIni()) {
            throw new ApiError(422, 'validasi_gagal', 'Isian belum benar.', [['kolom' => 'tanggal_rencana', 'pesan' => 'Isi hari ini atau sesudahnya.']]);
        }
        if (! DB::table('inti.kelas_rawat')->where('id', $isi['kelas_rawat_id'])->where('status', 'aktif')->exists()) {
            throw new ApiError(422, 'validasi_gagal', 'Isian belum benar.', [['kolom' => 'kelas_rawat_id', 'pesan' => 'Pilih kelas rawat yang aktif.']]);
        }
        $bangsal = $isi['bangsal_id'] ?? null;
        if ($bangsal !== null && ! DB::table('inti.bangsal')->where('id', $bangsal)->where('status', 'aktif')->exists()) {
            throw new ApiError(422, 'validasi_gagal', 'Isian belum benar.', [['kolom' => 'bangsal_id', 'pesan' => 'Pilih bangsal yang aktif.']]);
        }

        return DB::transaction(function () use ($dokter, $kunjunganId, $isi, $tanggal, $bangsal) {
            $k = KunciKunjungan::pasienLaluKunjungan($kunjunganId) ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
            $aturan = $this->periksaIzinSpri($dokter, $k);
            if (! in_array($k->status, Kunjungan::STATUS_AKTIF, true)) {
                throw new ApiError(409, 'status_tidak_sesuai', 'SPRI hanya untuk kunjungan yang masih terdaftar atau dilayani.',
                    [['jenis_kunjungan' => $k->jenis_kunjungan, 'status' => $k->status]]);
            }
            $episode = DB::table('kunjungan.episode')->where('id', $k->episode_id)->first(['id', 'jenis', 'status'])
                ?? throw new ApiError(409, 'status_tidak_sesuai', 'Episode kunjungan tidak ditemukan.');
            $this->pastikanBelumAda($k->id);

            // Perintah perpindahan (7.00.3: pemutus, alasan, asal, tujuan, status episode), pola disposisi IGD 2.26.
            $perpindahan = (string) Str::uuid7();
            $jam = self::jam();
            DB::table('kunjungan.perpindahan_pos')->insert([
                'id' => $perpindahan, 'episode_id' => $episode->id, 'dari_kunjungan_id' => $k->id, 'aturan_perpindahan_id' => $aturan->id,
                'pos_asal' => $aturan->pos_asal, 'pos_tujuan' => $aturan->pos_tujuan, 'izin_perintah' => $aturan->izin_perintah,
                'alasan' => trim($isi['alasan']), 'serah_terima' => null, 'status' => 'menunggu_kamar',
                'episode_jenis' => $episode->jenis, 'episode_status' => $episode->status, 'diputuskan_oleh' => $dokter->id,
                'terjadi_pada' => $jam, 'dibuat_pada' => $jam,
            ]);

            return $this->buat($k, 'poliklinik', $perpindahan, $dokter->id, $isi['kelas_rawat_id'], $bangsal, $tanggal);
        });
    }

    /** Pelanggan DisposisiRawatInap (di dalam transaksi disposisi; kunjungan sudah terkunci): permintaan kamar IGD. */
    public function dariDisposisi(DisposisiRawatInap $e): void
    {
        $k = Kunjungan::query()->findOrFail($e->kunjunganId);
        // Kelas belum dipilih di disposisi IGD: kosong = belum ditentukan, admisi memilih saat alokasi.
        $this->buat($k, 'igd', $e->perpindahanId, $e->diputuskanOleh, null, null, SuntinganBerversi::hariIni());
    }

    /** Pelanggan DisposisiDicatat: disposisi yang menutup kunjungan IGD membatalkan permintaan kamarnya. */
    public function disposisiDicatat(DisposisiDicatat $e): void
    {
        if ($e->status === 'selesai') {
            $this->batalkanAktif($e->kunjunganId, 'Disposisi IGD sesudah menunggu kamar: '.$e->posTujuan, $e->diputuskanOleh);
        }
    }

    /** Pelanggan KunjunganDibatalkan (di dalam transaksi pembatalan). */
    public function kunjunganDibatalkan(KunjunganDibatalkan $e): void
    {
        $this->batalkanAktif($e->kunjunganId, 'Kunjungan dibatalkan', $e->olehId);
    }

    /** @return string id permintaan */
    private function buat(Kunjungan $k, string $asal, string $perpindahanId, string $olehId, ?string $kelasId, ?string $bangsalId, string $tanggal): string
    {
        $this->pastikanBelumAda($k->id);
        $jam = self::jam();
        $spri = (string) Str::uuid7();
        DB::table('kunjungan.surat_kontrol')->insert([
            'id' => $spri, 'kunjungan_id' => $k->id, 'jenis' => 'spri', 'no_surat_bpjs' => null, 'tanggal_rencana' => $tanggal,
            'diterbitkan_oleh' => $olehId, 'diterbitkan_pada' => $jam, 'status' => 'aktif', 'dibuat_oleh' => $olehId, 'diubah_oleh' => $olehId,
        ]);
        $id = (string) Str::uuid7();
        DB::table('kunjungan.permintaan_tempat_tidur')->insert([
            'id' => $id, 'kunjungan_id' => $k->id, 'surat_kontrol_id' => $spri, 'perpindahan_pos_id' => $perpindahanId, 'asal' => $asal,
            'kelas_diminta_id' => $kelasId, 'bangsal_pilihan_id' => $bangsalId, 'diminta_oleh' => $olehId, 'diminta_pada' => $jam,
            'status' => 'menunggu', 'dibuat_oleh' => $olehId, 'diubah_oleh' => $olehId,
        ]);

        // FR-03-07: hanya permintaan IGD yang dieskalasi ke Kepala IGD (unit teguran jenis tugas).
        if ($asal === 'igd') {
            foreach ($this->pelaksanaTugas($olehId) as $pelaksana) {
                $this->kotakKerja->buat(self::JENIS_TUGAS, $id, "Pasien IGD {$k->nomor} menunggu kamar", $pelaksana);
            }
        }
        $this->audit->catat('rawat_inap.permintaan_kamar_dibuat', $olehId, 'kunjungan.permintaan_tempat_tidur', $id, [
            'kunjungan_id' => $k->id, 'nomor' => $k->nomor, 'asal' => $asal, 'surat_kontrol_id' => $spri, 'perpindahan_pos_id' => $perpindahanId,
            'kelas_diminta_id' => $kelasId, 'bangsal_pilihan_id' => $bangsalId, 'tanggal_rencana' => $tanggal,
        ]);

        return $id;
    }

    private function batalkanAktif(string $kunjunganId, string $alasan, ?string $olehId): void
    {
        $p = DB::table('kunjungan.permintaan_tempat_tidur')->where('kunjungan_id', $kunjunganId)->whereIn('status', self::AKTIF)
            ->lockForUpdate()->first(['id', 'status', 'surat_kontrol_id', 'tempat_tidur_id']);
        if ($p === null) {
            return;
        }
        $jam = self::jam();
        $batal = ['status' => 'batal', 'alasan_batal' => $alasan, 'dibatalkan_oleh' => $olehId, 'dibatalkan_pada' => $jam,
            'diubah_oleh' => $olehId, 'diubah_pada' => $jam, 'revisi' => DB::raw('revisi + 1')];
        DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $p->id)->update($batal);
        DB::table('kunjungan.surat_kontrol')->where('id', $p->surat_kontrol_id)->where('status', 'aktif')->update($batal);

        if ($p->status === 'dialokasikan' && $p->tempat_tidur_id !== null) {
            $tt = DB::table('inti.tempat_tidur')->where('id', $p->tempat_tidur_id)->first(['status_saat_ini', 'berlaku_sampai']);
            // TT yang sudah ditutup (penutupan bertanggal yang jatuh sesudah alokasi) tidak dihidupkan lagi.
            if ($tt !== null && $tt->status_saat_ini === StatusTempatTidur::DIPESAN && ! UbahStatusTempatTidur::ditutup($tt->berlaku_sampai)) {
                $this->statusTt->ubah($p->tempat_tidur_id, StatusTempatTidur::KOSONG_SIAP, StatusTempatTidur::SUMBER_ALOKASI_BATAL, $olehId, [StatusTempatTidur::DIPESAN]);
            }
        }
        $this->kotakKerja->selesaikan(self::JENIS_TUGAS, $p->id, 'Permintaan kamar dibatalkan', 'batal');
        $this->audit->catat('rawat_inap.permintaan_kamar_dibatalkan', $olehId, 'kunjungan.permintaan_tempat_tidur', $p->id,
            ['status' => 'batal', 'alasan_batal' => $alasan, 'tempat_tidur_id' => $p->tempat_tidur_id], ['status' => $p->status]);
    }

    private function periksaIzinSpri(Pengguna $dokter, Kunjungan $k): AturanPerpindahan
    {
        if ($k->jenis_kunjungan === Kunjungan::IGD) {
            throw new ApiError(409, 'spri_igd_lewat_disposisi', 'Rawat inap pasien IGD diputuskan lewat Disposisi IGD.',
                [['jenis_kunjungan' => $k->jenis_kunjungan, 'status' => $k->status]]);
        }
        $aturan = AturanPerpindahan::berlaku('poliklinik', 'rawat_inap')
            ?? throw new ApiError(422, 'perpindahan_di_luar_matriks', 'Rawat inap dari poliklinik tidak ada di matriks perpindahan aktif.',
                [['kolom' => 'kunjungan_id', 'pos_asal' => 'poliklinik', 'pos_tujuan' => 'rawat_inap', 'pesan' => 'Hubungi pengelola matriks perpindahan.']]);
        $unit = DB::table('inti.poliklinik')->where('id', $k->poli_id)->value('unit_organisasi_id');
        // Izin sel matriks lewat AccessEvaluator (lapis 1–3 dan 5), cakupan unit poli kunjungan.
        Gate::forUser($dokter)->authorize($aturan->izin_perintah, $unit === null ? Cakupan::rs() : new Cakupan('unit', (string) $unit));

        return $aturan;
    }

    private function pastikanBelumAda(string $kunjunganId): void
    {
        $ada = DB::table('kunjungan.permintaan_tempat_tidur')->where('kunjungan_id', $kunjunganId)->whereIn('status', self::AKTIF)->value('id');
        if ($ada !== null) {
            throw new ApiError(409, 'permintaan_kamar_sudah_ada', 'Kunjungan ini sudah punya permintaan kamar yang masih berjalan.',
                [['permintaan_tempat_tidur_id' => $ada]]);
        }
    }

    /**
     * Pelaksana tugas menunggu kamar: setiap peran aktif pemegang pendaftaran.admisi.input (petugas alokasi); tanpa peran
     * itu, pemberi perintah. Teguran dan eskalasi ke Kepala IGD lewat unit teguran jenis tugas (1.7).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function pelaksanaTugas(string $olehId): array
    {
        $peran = Peran::query()->where('status', 'aktif')->whereIn('id', DB::table('akses.peran_izin')->where('status', 'aktif')
            ->whereIn('izin_id', DB::table('akses.izin')->where('kode', 'pendaftaran.admisi.input')->select('id'))->select('peran_id'))
            ->orderBy('kode')->pluck('id')->map(fn ($id) => ['peran', (string) $id])->all();

        return $peran === [] ? [['pengguna', $olehId]] : array_values($peran);
    }

    /** Jam server dengan mikrodetik (binding Carbon bawaan membuangnya; urutan antrean memakai jam ini). */
    private static function jam(): string
    {
        return now()->utc()->format('Y-m-d H:i:s.uP');
    }
}
