<?php

namespace App\Modules\Akses\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Satu-satunya penulis akses.relasi_perawatan (tiket 2.6). Dipanggil modul pelayanan di dalam transaksinya sendiri;
 * jejak audit ikut aksi pemicunya (mis. `kunjungan.dibuat` memuat id relasi).
 */
final class KelolaRelasiPerawatan
{
    public const PERAN_DOKTER_JADWAL = 'dokter_jadwal';

    public function berikan(string $pasienId, string $pegawaiId, string $peran, string $sumberJenis, string $sumberId,
        Carbon $dari, Carbon $sampai, ?string $olehId): string
    {
        $id = (string) Str::uuid7();
        DB::table('akses.relasi_perawatan')->insert([
            'id' => $id, 'pasien_id' => $pasienId, 'pegawai_id' => $pegawaiId, 'peran' => $peran,
            'sumber_jenis' => $sumberJenis, 'sumber_id' => $sumberId, // Dengan offset: Carbon zona RS tanpa offset akan dibaca sebagai zona sesi DB.
            'berlaku_dari' => $dari->format('Y-m-d H:i:s.uP'), 'berlaku_sampai' => $sampai->format('Y-m-d H:i:s.uP'),
            'dibuat_oleh' => $olehId, 'diubah_oleh' => $olehId,
        ]);

        return $id;
    }

    /** Akhiri semua relasi aktif yang lahir dari satu objek (mis. kunjungan batal). $olehId null = sistem. */
    public function akhiri(string $sumberJenis, string $sumberId, string $alasan, ?string $olehId): int
    {
        return DB::table('akses.relasi_perawatan')->where('sumber_jenis', $sumberJenis)->where('sumber_id', $sumberId)
            ->where('status', 'aktif')
            ->update([
                'status' => 'batal', 'alasan_batal' => $alasan, 'dibatalkan_oleh' => $olehId, 'dibatalkan_pada' => now(),
                'diubah_oleh' => $olehId, 'diubah_pada' => now(), 'revisi' => DB::raw('revisi + 1'),
            ]);
    }
}
