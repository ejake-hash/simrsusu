<?php

namespace App\Modules\Pendaftaran\Application;

use App\Core\Http\ApiError;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\Pendaftaran\Models\Pasien;

/**
 * Satu sumber urutan kunci baris kunjungan: pasien lalu kunjungan, di dalam transaksi pemanggil. Dipakai pendaftaran
 * (pembatalan), BentukEpisodeIgd, triase, dan penulis aktivitas kunjungan (CPPT, asesmen awal, order penunjang) agar
 * tidak deadlock satu sama lain dan dengan gabung RM (tinjauan #191). Kolom kunjungan (episode_id, pasien_id, status)
 * dibaca dari baris terkunci ini, bukan dari objek yang dibaca sebelum transaksi: pemindahan episode IGD > 6 jam yang
 * commit lebih dulu ikut terlihat.
 */
final class KunciKunjungan
{
    /**
     * null = kunjungan tidak ada. Gabung RM yang commit di antara baca dan kunci memindahkan kunjungan ke pasien lain:
     * kunci yang dipegang milik pasien lama, dan mengunci pasien baru sesudah kunjungan membalik urutan. Bawaan: 409
     * `pasien_digabung` (pengguna mengulang); `$lewatiBilaPindah` untuk tugas sistem: null, putaran berikutnya mengulang.
     */
    public static function pasienLaluKunjungan(string $kunjunganId, bool $lewatiBilaPindah = false): ?Kunjungan
    {
        $pasienId = Kunjungan::query()->whereKey($kunjunganId)->value('pasien_id');
        if ($pasienId === null) {
            return null;
        }
        Pasien::query()->whereKey($pasienId)->lockForUpdate()->first();
        $kunjungan = Kunjungan::query()->whereKey($kunjunganId)->lockForUpdate()->first();
        if ($kunjungan !== null && $kunjungan->pasien_id !== $pasienId) {
            if ($lewatiBilaPindah) {
                return null;
            }
            throw new ApiError(409, 'pasien_digabung', 'No. RM pasien kunjungan ini baru saja digabung. Muat ulang lalu coba lagi.');
        }

        return $kunjungan;
    }
}
