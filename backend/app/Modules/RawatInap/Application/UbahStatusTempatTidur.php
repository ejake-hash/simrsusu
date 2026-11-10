<?php

namespace App\Modules\RawatInap\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\SuntinganBerversi;
use App\Modules\RawatInap\Domain\StatusTempatTidur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Satu-satunya jalur pengubah status TT (tiket 2.27; FR-08-01, FR-08-04, PRD 11.2 `riwayat_status_tempat_tidur`), dipakai
 * ulang alokasi (2.28) dan penempatan/pindah/pulang (2.29). Baris inti.tempat_tidur dikunci FOR UPDATE, status asal
 * dicocokkan dengan transisi yang sah menurut pemanggil, lalu status dan riwayat ditulis bersama audit di akhir transaksi.
 * Jam dari kode (`now()`), bukan bawaan database, agar ikut travel waktu tes. Audit hanya kode TT dan status, tanpa data
 * pasien. Kolom `revisi` TT tidak dinaikkan: status bukan suntingan data induk, jadi tidak membuat If-Match penyunting basi.
 */
final class UbahStatusTempatTidur
{
    public function __construct(private readonly CatatAktivitas $audit) {}

    /** Perubahan yang diminta petugas ruangan; transisinya dibatasi StatusTempatTidur::TRANSISI_PETUGAS. */
    public function olehPetugas(string $tempatTidurId, string $status, string $penggunaId): void
    {
        $this->ubah($tempatTidurId, $status, StatusTempatTidur::SUMBER_PETUGAS, $penggunaId, StatusTempatTidur::TRANSISI_PETUGAS[$status] ?? []);
    }

    /**
     * @param  list<string>  $dariYangSah  status asal yang boleh berpindah ke $status untuk kejadian ini
     * @param  string|null  $olehId  akses.pengguna.id; null = kejadian sistem
     */
    public function ubah(string $tempatTidurId, string $status, string $sumber, ?string $olehId, array $dariYangSah): void
    {
        if (! in_array($status, StatusTempatTidur::SEMUA, true) || ! in_array($sumber, StatusTempatTidur::SUMBER, true)) {
            throw new InvalidArgumentException('Status atau sumber kejadian TT tidak dikenal.');
        }

        DB::transaction(function () use ($tempatTidurId, $status, $sumber, $olehId, $dariYangSah) {
            $tt = DB::table('inti.tempat_tidur')->where('id', $tempatTidurId)->lockForUpdate()
                ->first(['id', 'kode', 'status_saat_ini', 'berlaku_sampai']);
            if ($tt === null) {
                throw new ApiError(404, 'tidak_ditemukan', 'Tempat tidur tidak ditemukan.');
            }
            if (self::ditutup($tt->berlaku_sampai)) {
                throw new ApiError(409, 'tempat_tidur_ditutup', 'Tempat tidur ini sudah ditutup dan tidak dipakai lagi.');
            }
            $sebelum = (string) $tt->status_saat_ini;
            if (! in_array($sebelum, $dariYangSah, true)) {
                throw new ApiError(409, 'transisi_status_tempat_tidur_tidak_sah', 'Status tempat tidur sudah berubah atau tidak bisa diubah ke status ini.',
                    [['status_sekarang' => $sebelum, 'status_diminta' => $status]]);
            }

            // Format eksplisit: binding Carbon bawaan Laravel membuang mikrodetik.
            $sekarang = now()->utc()->format('Y-m-d H:i:s.uP');
            DB::table('inti.tempat_tidur')->where('id', $tt->id)->update(['status_saat_ini' => $status, 'status_sejak' => $sekarang]);
            DB::table('kunjungan.riwayat_status_tempat_tidur')->insert([
                'id' => (string) Str::uuid7(), 'tempat_tidur_id' => $tt->id, 'status_sebelum' => $sebelum, 'status' => $status,
                'terjadi_pada' => $sekarang, 'oleh' => $olehId, 'sumber_kejadian' => $sumber,
            ]);
            $this->audit->catat('rawat_inap.status_tempat_tidur_diubah', $olehId, 'inti.tempat_tidur', $tt->id,
                ['kode' => $tt->kode, 'status' => $status, 'sumber_kejadian' => $sumber], ['kode' => $tt->kode, 'status' => $sebelum]);
        });
    }

    /** TT ditutup (1.8b): hari terakhir dihitung (`berlaku_sampai`) sudah lewat menurut tanggal zona RS. */
    public static function ditutup(mixed $berlakuSampai): bool
    {
        return $berlakuSampai !== null && substr((string) $berlakuSampai, 0, 10) < SuntinganBerversi::hariIni();
    }
}
