<?php

namespace App\Modules\Pendaftaran\Application;

use App\Core\Http\ApiError;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\Pendaftaran\Models\Pasien;

/**
 * Kunci baris pasien lalu kunjungan di dalam transaksi pemanggil, urutan yang sama dengan pendaftaran, pembatalan,
 * gabung RM, dan BentukEpisodeIgd agar penulis aktivitas kunjungan tidak deadlock dengan mereka (tinjauan #191).
 * Kolom kunjungan (episode_id, pasien_id, status) dibaca dari baris terkunci ini, bukan dari objek yang dibaca
 * controller di luar transaksi: pemindahan episode IGD > 6 jam yang commit lebih dulu ikut terlihat.
 */
final class KunciKunjungan
{
    /** null = kunjungan tidak ada. */
    public static function pasienLaluKunjungan(string $kunjunganId): ?Kunjungan
    {
        $pasienId = Kunjungan::query()->whereKey($kunjunganId)->value('pasien_id');
        if ($pasienId === null) {
            return null;
        }
        Pasien::query()->whereKey($pasienId)->lockForUpdate()->first();
        $kunjungan = Kunjungan::query()->whereKey($kunjunganId)->lockForUpdate()->first();
        // Gabung RM yang commit di antara baca dan kunci memindahkan kunjungan ke pasien lain: kunci yang dipegang
        // milik pasien lama. Mengunci pasien baru sesudah kunjungan membalik urutan, jadi minta pengguna mengulang.
        if ($kunjungan !== null && $kunjungan->pasien_id !== $pasienId) {
            throw new ApiError(409, 'pasien_digabung', 'No. RM pasien kunjungan ini baru saja digabung. Muat ulang lalu coba lagi.');
        }

        return $kunjungan;
    }
}
