<?php

namespace App\Modules\RawatInap\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\SuntinganBerversi;
use App\Core\Instalasi\StatusInstalasi;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\AturanPerpindahan;
use App\Modules\Pendaftaran\Application\KelolaPasien;
use App\Modules\Pendaftaran\Application\KunciKunjungan;
use App\Modules\Pendaftaran\Models\Episode;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\RawatInap\Domain\StatusTempatTidur;
use App\Modules\RawatInap\Events\PasienPindahRuang;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Pindah ruang/kelas pasien rawat inap (tiket 2.29; FR-08-06, FR-08-04, FR-00-02, FR-00-03, PRD 7.00.3 baris "Rawat inap"
 * → "Rawat inap"). Perintah sel matriks rawat_inap → rawat_inap: izin sel di cakupan mana pun (termasuk SIP/SPK) sebelum
 * keberadaan kunjungan dibaca, lalu cakupan unit bangsal asal (pola SPRI 2.28). Satu transaksi: kunci pasien → kunjungan
 * (KunciKunjungan) → penempatan aktif → kedua TT FOR UPDATE berurutan id. Perintah baru `dilaksanakan` di
 * kunjungan.perpindahan_pos (serah terima RM-15, linimasa FR-00-03), penempatan lama selesai (`pindah`), TT lama
 * `terisi → dibersihkan`, penempatan baru, TT baru `kosong_siap → terisi` (sumber `pindah_ruang`), unit kunjungan dan kelas
 * dirawat ikut TT baru. Pindah kelas boleh; titip/naik kelas dan biayanya di Tahap D. DPJP tidak berubah, jadi relasi
 * perawatannya tetap. Alasan dan serah terima klinis: tidak ke log/audit.
 */
final class PindahRuang
{
    public const POS = 'rawat_inap';

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly StatusInstalasi $instalasi,
        private readonly UbahStatusTempatTidur $statusTt,
    ) {}

    /**
     * Sebelum isian dibaca: sel matriks nonaktif → 422; izin sel di cakupan mana pun → 403; kunjungan tidak ada → 404;
     * izin sel tidak mencakup unit bangsal asal → 403.
     */
    public function izinkan(Pengguna $dokter, string $kunjunganId): void
    {
        $aturan = self::aturan();
        Gate::forUser($dokter)->authorize($aturan->izin_perintah);
        $k = Kunjungan::query()->find($kunjunganId, ['id', 'unit_id']) ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
        self::periksaCakupan($dokter, $k, $aturan);
    }

    /**
     * @param  array{tempat_tidur_id: string, alasan: string, serah_terima: string}  $isi  sudah divalidasi bentuknya
     * @return string id perintah (kunjungan.perpindahan_pos)
     */
    public function pindahkan(Pengguna $dokter, string $kunjunganId, array $isi): string
    {
        KelolaPasien::pastikanSiapMelayani($this->instalasi);

        return DB::transaction(function () use ($dokter, $kunjunganId, $isi) {
            $k = KunciKunjungan::pasienLaluKunjungan($kunjunganId) ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
            $aturan = self::aturan();
            self::periksaCakupan($dokter, $k, $aturan);
            if ($k->jenis_kunjungan !== Kunjungan::RAWAT_INAP || ! in_array($k->status, Kunjungan::STATUS_AKTIF, true)) {
                throw new ApiError(409, 'status_tidak_sesuai', 'Pindah ruang hanya untuk kunjungan rawat inap yang masih berjalan.',
                    [['jenis_kunjungan' => $k->jenis_kunjungan, 'status' => $k->status]]);
            }
            $lama = DB::table('kunjungan.penempatan_tempat_tidur')->where('kunjungan_id', $k->id)->whereNull('selesai_pada')->lockForUpdate()
                ->first(['id', 'tempat_tidur_id'])
                ?? throw new ApiError(409, 'status_tidak_sesuai', 'Pasien ini belum menempati TT.', [['jenis_kunjungan' => $k->jenis_kunjungan, 'status' => $k->status]]);
            $tujuanId = $isi['tempat_tidur_id'];
            if ($tujuanId === $lama->tempat_tidur_id) {
                throw new ApiError(422, 'validasi_gagal', 'Isian belum benar.', [['kolom' => 'tempat_tidur_id', 'pesan' => 'Pilih TT selain TT pasien sekarang.']]);
            }
            // Kedua TT dikunci berurutan id: dua pindah ruang bersilang tidak saling menunggu.
            $tt = DB::table('inti.tempat_tidur')->whereIn('id', [$lama->tempat_tidur_id, $tujuanId])->orderBy('id')->lockForUpdate()
                ->get(['id', 'kode', 'kamar_id', 'kelas_rawat_id', 'status_saat_ini', 'berlaku_dari', 'berlaku_sampai'])->keyBy('id');
            $tujuan = $tt->get($tujuanId)
                ?? throw new ApiError(422, 'validasi_gagal', 'Isian belum benar.', [['kolom' => 'tempat_tidur_id', 'pesan' => 'Tempat tidur tidak ditemukan.']]);
            $asalTt = $tt->get($lama->tempat_tidur_id);
            if ($tujuan->berlaku_sampai !== null) {
                throw new ApiError(409, 'tempat_tidur_ditutup', 'Tempat tidur ini sudah ditutup atau dijadwalkan tutup.');
            }
            if ($tujuan->status_saat_ini !== StatusTempatTidur::KOSONG_SIAP || substr((string) $tujuan->berlaku_dari, 0, 10) > SuntinganBerversi::hariIni()) {
                throw new ApiError(409, 'tempat_tidur_tidak_tersedia', 'Tempat tidur ini tidak kosong siap. Muat ulang peta lalu pilih TT lain.',
                    [['status_sekarang' => $tujuan->status_saat_ini]]);
            }
            $unitBaru = (string) DB::table('inti.kamar as km')->join('inti.bangsal as b', 'b.id', '=', 'km.bangsal_id')
                ->where('km.id', $tujuan->kamar_id)->value('b.unit_organisasi_id');
            $episode = Episode::query()->whereKey($k->episode_id)->lockForUpdate()->firstOrFail();

            $jam = now()->utc()->format('Y-m-d H:i:s.uP');
            $perpindahan = (string) Str::uuid7();
            DB::table('kunjungan.perpindahan_pos')->insert([
                'id' => $perpindahan, 'episode_id' => $episode->id, 'dari_kunjungan_id' => $k->id, 'aturan_perpindahan_id' => $aturan->id,
                'pos_asal' => $aturan->pos_asal, 'pos_tujuan' => $aturan->pos_tujuan, 'izin_perintah' => $aturan->izin_perintah,
                'alasan' => trim($isi['alasan']), 'serah_terima' => trim($isi['serah_terima']), 'status' => 'dilaksanakan',
                'episode_jenis' => $episode->jenis, 'episode_status' => $episode->status, 'diputuskan_oleh' => $dokter->id,
                'terjadi_pada' => $jam, 'dibuat_pada' => $jam,
            ]);
            DB::table('kunjungan.penempatan_tempat_tidur')->where('id', $lama->id)->update([
                'selesai_pada' => $jam, 'alasan_selesai' => 'pindah', 'diakhiri_oleh' => $dokter->id,
                'diubah_oleh' => $dokter->id, 'diubah_pada' => $jam, 'revisi' => DB::raw('revisi + 1'),
            ]);
            $this->statusTt->ubah($lama->tempat_tidur_id, StatusTempatTidur::DIBERSIHKAN, StatusTempatTidur::SUMBER_PINDAH_RUANG, $dokter->id, StatusTempatTidur::DITEMPATI);
            $baru = (string) Str::uuid7();
            DB::table('kunjungan.penempatan_tempat_tidur')->insert([
                'id' => $baru, 'tempat_tidur_id' => $tujuan->id, 'kunjungan_id' => $k->id, 'pasien_id' => $k->pasien_id,
                'perpindahan_pos_id' => $perpindahan, 'serah_terima' => null, 'mulai_pada' => $jam, 'ditempatkan_oleh' => $dokter->id,
                'dibuat_oleh' => $dokter->id, 'diubah_oleh' => $dokter->id, 'dibuat_pada' => $jam, 'diubah_pada' => $jam,
            ]);
            $this->statusTt->ubah($tujuan->id, StatusTempatTidur::TERISI, StatusTempatTidur::SUMBER_PINDAH_RUANG, $dokter->id, [StatusTempatTidur::KOSONG_SIAP]);

            $unitLama = $k->unit_id;
            $k->forceFill(['unit_id' => $unitBaru, 'diubah_oleh' => $dokter->id, 'revisi' => $k->revisi + 1])->save();
            $kelasLama = DB::table('kunjungan.rawat_inap')->where('kunjungan_id', $k->id)->value('kelas_dirawat_id');
            DB::table('kunjungan.rawat_inap')->where('kunjungan_id', $k->id)->update([
                'kelas_dirawat_id' => $tujuan->kelas_rawat_id, 'diubah_oleh' => $dokter->id, 'diubah_pada' => $jam, 'revisi' => DB::raw('revisi + 1'),
            ]);

            PasienPindahRuang::dispatch($perpindahan, $k->id, $k->pasien_id, $lama->tempat_tidur_id, $tujuan->id, $baru);
            $this->audit->catat('rawat_inap.pindah_ruang', $dokter->id, 'kunjungan.perpindahan_pos', $perpindahan, [
                'kunjungan_id' => $k->id, 'nomor' => $k->nomor, 'penempatan_id' => $baru, 'tempat_tidur_id' => $tujuan->id, 'kode' => $tujuan->kode,
                'unit_id' => $unitBaru, 'kelas_dirawat_id' => $tujuan->kelas_rawat_id, 'episode_id' => $episode->id, 'aturan_perpindahan_id' => $aturan->id,
            ], [
                'penempatan_id' => $lama->id, 'tempat_tidur_id' => $lama->tempat_tidur_id, 'kode' => $asalTt?->kode, 'unit_id' => $unitLama,
                'kelas_dirawat_id' => $kelasLama,
            ]);

            return $perpindahan;
        });
    }

    private static function aturan(): AturanPerpindahan
    {
        return AturanPerpindahan::berlaku(self::POS, self::POS)
            ?? throw new ApiError(422, 'perpindahan_di_luar_matriks', 'Pindah ruang tidak ada di matriks perpindahan aktif.',
                [['kolom' => 'tempat_tidur_id', 'pos_asal' => self::POS, 'pos_tujuan' => self::POS, 'pesan' => 'Hubungi pengelola matriks perpindahan.']]);
    }

    /** Izin sel matriks lewat AccessEvaluator (lapis 1–3 dan 5), cakupan unit bangsal kunjungan saat ini. */
    private static function periksaCakupan(Pengguna $dokter, Kunjungan $k, AturanPerpindahan $aturan): void
    {
        Gate::forUser($dokter)->authorize($aturan->izin_perintah, $k->unit_id === null ? Cakupan::rs() : new Cakupan('unit', $k->unit_id));
    }
}
