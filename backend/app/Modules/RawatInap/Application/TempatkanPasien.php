<?php

namespace App\Modules\RawatInap\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Instalasi\StatusInstalasi;
use App\Modules\Akses\Application\KelolaRelasiPerawatan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Application\BentukEpisodeRawatInap;
use App\Modules\Pendaftaran\Application\KelolaKunjungan;
use App\Modules\Pendaftaran\Application\KelolaPasien;
use App\Modules\Pendaftaran\Application\KunciKunjungan;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\RawatInap\Domain\StatusTempatTidur;
use App\Modules\RawatInap\Events\PasienDitempatkan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Penempatan pasien di TT yang sudah dialokasikan (tiket 2.29; FR-08-04, FR-08-06, FR-00-01, PRD 7.00.2 baris "Rawat
 * inap"). Satu transaksi, urutan kunci pasien → kunjungan asal (KunciKunjungan) → permintaan → TT (sama dengan alokasi dan
 * pembatalan): kunjungan bangsal jenis `rawat_inap` dibuka (unit = unit bangsal TT, DPJP awal = dokter pemberi perintah
 * rawat), episode rawat inap dibentuk/digabung (BentukEpisodeRawatInap), penempatan dibuka dengan jam server, TT
 * `dipesan → terisi` (UbahStatusTempatTidur, sumber `penempatan`), permintaan `ditempatkan` (perintah menunggu_kamar ikut
 * selesai: penempatan menyimpan id perintah dan serah terima saat tiba), kunjungan IGD asal yang masih aktif selesai
 * (keluar dari papan IGD), DPJP mendapat relasi perawatan lewat KelolaRelasiPerawatan. Satu pasien satu penempatan aktif
 * (409 `pasien_sudah_ditempatkan`; indeks unik parsial sebagai penjaga terakhir).
 */
final class TempatkanPasien
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly StatusInstalasi $instalasi,
        private readonly UbahStatusTempatTidur $statusTt,
        private readonly KelolaKunjungan $kunjungan,
        private readonly BentukEpisodeRawatInap $episode,
        private readonly KelolaRelasiPerawatan $relasi,
    ) {}

    /** @return string id kunjungan rawat inap */
    public function tempatkan(Pengguna $petugas, string $permintaanId, ?string $serahTerima): string
    {
        KelolaPasien::pastikanSiapMelayani($this->instalasi);

        return DB::transaction(function () use ($petugas, $permintaanId, $serahTerima) {
            $kunjunganAsalId = DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $permintaanId)->value('kunjungan_id')
                ?? throw new ApiError(404, 'tidak_ditemukan', 'Permintaan kamar tidak ditemukan.');
            $asal = KunciKunjungan::pasienLaluKunjungan((string) $kunjunganAsalId) ?? throw new ApiError(404, 'tidak_ditemukan', 'Permintaan kamar tidak ditemukan.');
            $p = DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $permintaanId)->lockForUpdate()
                ->first(['id', 'status', 'asal', 'tempat_tidur_id', 'diminta_oleh', 'menunggu_sejak', 'perpindahan_pos_id'])
                ?? throw new ApiError(404, 'tidak_ditemukan', 'Permintaan kamar tidak ditemukan.');
            if ($p->status !== 'dialokasikan' || $p->tempat_tidur_id === null) {
                throw new ApiError(409, 'permintaan_kamar_tidak_dialokasikan', 'Permintaan kamar ini belum dialokasikan TT, sudah ditempatkan, atau batal. Muat ulang antrean.',
                    [['status' => $p->status]]);
            }
            if (DB::table('kunjungan.penempatan_tempat_tidur')->where('pasien_id', $asal->pasien_id)->whereNull('selesai_pada')->exists()) {
                throw new ApiError(409, 'pasien_sudah_ditempatkan', 'Pasien ini sudah menempati TT lain. Pindahkan lewat pindah ruang.');
            }
            $tt = DB::table('inti.tempat_tidur')->where('id', $p->tempat_tidur_id)->lockForUpdate()
                ->first(['id', 'kode', 'kamar_id', 'kelas_rawat_id', 'status_saat_ini'])
                ?? throw new ApiError(404, 'tidak_ditemukan', 'Tempat tidur tidak ditemukan.');
            // Pesanan TT dilepas hanya lewat batal permintaan; status lain berarti peta berubah di luar alur ini.
            if ($tt->status_saat_ini !== StatusTempatTidur::DIPESAN) {
                throw new ApiError(409, 'tempat_tidur_tidak_tersedia', 'Tempat tidur ini tidak lagi dipesan untuk permintaan ini. Muat ulang peta.',
                    [['status_sekarang' => $tt->status_saat_ini]]);
            }
            $unit = (string) DB::table('inti.kamar as km')->join('inti.bangsal as b', 'b.id', '=', 'km.bangsal_id')
                ->where('km.id', $tt->kamar_id)->value('b.unit_organisasi_id');

            $sekarang = now()->utc();
            $jam = $sekarang->format('Y-m-d H:i:s.uP');
            // Awal rawat inap = jam SPRI, atau awal tanggal rencana; penempatan sebelum tanggal rencana mulai saat ditempatkan.
            $awal = Carbon::parse((string) $p->menunggu_sejak)->utc();
            if ($awal->greaterThan($sekarang)) {
                $awal = $sekarang->copy();
            }
            $tanggal = $awal->copy()->setTimezone((string) config('instalasi.zona_waktu'))->toDateString();

            $episode = $this->episode->bentuk($asal, $tanggal, $petugas->id);
            $asal->refresh();
            $dpjp = DB::table('akses.pengguna')->where('id', $p->diminta_oleh)->value('pegawai_id');
            $ri = $this->kunjungan->bukaRawatInap($asal, $episode->id, $unit, $dpjp === null ? null : (string) $dpjp, $petugas->id);

            $standar = ['dibuat_oleh' => $petugas->id, 'diubah_oleh' => $petugas->id, 'dibuat_pada' => $jam, 'diubah_pada' => $jam];
            DB::table('kunjungan.rawat_inap')->insert([
                'id' => (string) Str::uuid7(), 'kunjungan_id' => $ri->id, 'permintaan_tempat_tidur_id' => $p->id, 'asal' => $p->asal,
                'kelas_hak_id' => null, 'kelas_dirawat_id' => $tt->kelas_rawat_id, 'mulai_pada' => $awal->format('Y-m-d H:i:s.uP'), ...$standar,
            ]);
            $penempatan = (string) Str::uuid7();
            DB::table('kunjungan.penempatan_tempat_tidur')->insert([
                'id' => $penempatan, 'tempat_tidur_id' => $tt->id, 'kunjungan_id' => $ri->id, 'pasien_id' => $asal->pasien_id,
                'perpindahan_pos_id' => $p->perpindahan_pos_id, 'serah_terima' => self::teks($serahTerima), 'mulai_pada' => $jam,
                'ditempatkan_oleh' => $petugas->id, ...$standar,
            ]);
            $this->statusTt->ubah($tt->id, StatusTempatTidur::TERISI, StatusTempatTidur::SUMBER_PENEMPATAN, $petugas->id, [StatusTempatTidur::DIPESAN]);
            DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $p->id)->update([
                'status' => 'ditempatkan', 'diubah_oleh' => $petugas->id, 'diubah_pada' => $jam, 'revisi' => DB::raw('revisi + 1'),
            ]);

            // Pasien IGD pindah ke bangsal: kunjungan IGD selesai (keluar dari papan IGD), pola disposisi 2.26.
            $igdSelesai = false;
            if ($asal->jenis_kunjungan === Kunjungan::IGD && in_array($asal->status, Kunjungan::STATUS_AKTIF, true)) {
                $asal->forceFill(['status' => 'selesai', 'diubah_oleh' => $petugas->id, 'revisi' => $asal->revisi + 1])->save();
                $this->relasi->akhiri('kunjungan', $asal->id, 'Pasien ditempatkan di rawat inap', $petugas->id);
                $igdSelesai = true;
            }
            $relasi = $dpjp === null ? null : $this->relasi->berikan($asal->pasien_id, (string) $dpjp, KelolaRelasiPerawatan::PERAN_DPJP_RAWAT_INAP,
                'kunjungan', $ri->id, $sekarang, null, $petugas->id);

            PasienDitempatkan::dispatch($penempatan, $ri->id, $asal->pasien_id, $episode->id, $tt->id, $p->id);
            $this->audit->catat('rawat_inap.pasien_ditempatkan', $petugas->id, 'kunjungan.penempatan_tempat_tidur', $penempatan, [
                'kunjungan_id' => $ri->id, 'nomor' => $ri->nomor, 'permintaan_tempat_tidur_id' => $p->id, 'tempat_tidur_id' => $tt->id,
                'kode' => $tt->kode, 'episode_id' => $episode->id, 'kunjungan_asal_id' => $asal->id, 'kunjungan_asal_selesai' => $igdSelesai,
                'relasi_perawatan_id' => $relasi, 'serah_terima_diisi' => self::teks($serahTerima) !== null,
            ]);

            return $ri->id;
        });
    }

    private static function teks(?string $nilai): ?string
    {
        return $nilai === null || trim($nilai) === '' ? null : trim($nilai);
    }
}
