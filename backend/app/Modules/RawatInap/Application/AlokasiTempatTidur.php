<?php

namespace App\Modules\RawatInap\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Notifikasi\Application\KotakKerja;
use App\Modules\RawatInap\Domain\StatusTempatTidur;
use Illuminate\Support\Facades\DB;

/**
 * Alokasi TT untuk permintaan kamar (tiket 2.28; FR-08-03, FR-03-06). Kunci baris permintaan, lalu baris TT FOR UPDATE
 * (urutan sama dengan pembatalan permintaan dan penutupan TT): dua alokasi serentak ke TT yang sama → yang kedua menunggu,
 * lalu membaca status `dipesan` → 409. TT harus `kosong_siap`, tidak ditutup atau dijadwalkan tutup (1.8b), sudah berlaku hari ini, dan
 * kelasnya sama dengan kelas diminta (titip/naik kelas di luar tiket ini). Status TT berubah lewat UbahStatusTempatTidur
 * (sumber `alokasi`); tugas menunggu kamar selesai.
 */
final class AlokasiTempatTidur
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly KotakKerja $kotakKerja,
        private readonly UbahStatusTempatTidur $statusTt,
    ) {}

    public function alokasikan(Pengguna $petugas, string $permintaanId, string $tempatTidurId): void
    {
        DB::transaction(function () use ($petugas, $permintaanId, $tempatTidurId) {
            $p = DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $permintaanId)->lockForUpdate()
                ->first(['id', 'status', 'kelas_diminta_id', 'kunjungan_id'])
                ?? throw new ApiError(404, 'tidak_ditemukan', 'Permintaan kamar tidak ditemukan.');
            if ($p->status !== 'menunggu') {
                throw new ApiError(409, 'permintaan_kamar_tidak_menunggu', 'Permintaan kamar ini sudah dialokasikan, ditempatkan, atau batal. Muat ulang antrean.',
                    [['status' => $p->status]]);
            }
            $tt = DB::table('inti.tempat_tidur')->where('id', $tempatTidurId)->lockForUpdate()
                ->first(['id', 'kode', 'kelas_rawat_id', 'status_saat_ini', 'berlaku_dari', 'berlaku_sampai'])
                ?? throw new ApiError(404, 'tidak_ditemukan', 'Tempat tidur tidak ditemukan.');
            // Ditutup atau penutupannya sudah dijadwalkan (berlaku_sampai terisi): tidak dipesan (sama dengan `dapat_dipilih` peta).
            if ($tt->berlaku_sampai !== null) {
                throw new ApiError(409, 'tempat_tidur_ditutup', 'Tempat tidur ini sudah ditutup atau dijadwalkan tutup.');
            }
            if ($tt->status_saat_ini !== StatusTempatTidur::KOSONG_SIAP || substr((string) $tt->berlaku_dari, 0, 10) > SuntinganBerversi::hariIni()) {
                throw new ApiError(409, 'tempat_tidur_tidak_tersedia', 'Tempat tidur ini tidak kosong siap. Muat ulang peta lalu pilih TT lain.',
                    [['status_sekarang' => $tt->status_saat_ini]]);
            }
            if ($p->kelas_diminta_id !== null && $tt->kelas_rawat_id !== $p->kelas_diminta_id) {
                throw new ApiError(422, 'kelas_tidak_sesuai', 'Kelas tempat tidur berbeda dengan kelas yang diminta.',
                    [['kolom' => 'tempat_tidur_id', 'pesan' => 'Pilih TT dengan kelas yang diminta.']]);
            }

            $this->statusTt->ubah($tt->id, StatusTempatTidur::DIPESAN, StatusTempatTidur::SUMBER_ALOKASI, $petugas->id, [StatusTempatTidur::KOSONG_SIAP]);
            $jam = now()->utc()->format('Y-m-d H:i:s.uP');
            DB::table('kunjungan.permintaan_tempat_tidur')->where('id', $p->id)->update([
                'status' => 'dialokasikan', 'tempat_tidur_id' => $tt->id, 'dialokasikan_oleh' => $petugas->id, 'dialokasikan_pada' => $jam,
                'diubah_oleh' => $petugas->id, 'diubah_pada' => $jam, 'revisi' => DB::raw('revisi + 1'),
            ]);
            $this->kotakKerja->selesaikan(PermintaanKamar::JENIS_TUGAS, $p->id, 'Tempat tidur dialokasikan');
            $this->audit->catat('rawat_inap.tempat_tidur_dialokasikan', $petugas->id, 'kunjungan.permintaan_tempat_tidur', $p->id,
                ['status' => 'dialokasikan', 'tempat_tidur_id' => $tt->id, 'kode' => $tt->kode, 'kunjungan_id' => $p->kunjungan_id], ['status' => 'menunggu']);
        });
    }
}
