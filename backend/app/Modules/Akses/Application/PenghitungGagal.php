<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Support\Facades\DB;

/**
 * Satu penghitung gagal untuk sandi dan kode MFA (PRD 10.3.2): 5 kali gagal beruntun mengunci
 * akun 15 menit. Direset hanya saat masuk selesai seluruhnya (MasukPengguna::selesaikan), bukan
 * saat sandi benar; kalau tidak, sandi yang diketahui bisa dipakai untuk menebak kode MFA tanpa batas.
 */
final class PenghitungGagal
{
    public function __construct(private readonly CatatAktivitas $audit) {}

    public function catat(Pengguna $pengguna): void
    {
        $maks = (int) config('autentikasi.penguncian.maks_gagal');

        // Atomik; akun yang sedang terkunci sudah ditolak pemanggil, jadi terkunci() sesudah ini
        // berarti baru saja terkunci.
        DB::update(
            'UPDATE akses.pengguna
                SET percobaan_gagal = CASE WHEN percobaan_gagal + 1 >= ? THEN 0 ELSE percobaan_gagal + 1 END,
                    terkunci_sampai = CASE WHEN percobaan_gagal + 1 >= ? THEN CAST(? AS timestamptz) ELSE terkunci_sampai END
              WHERE id = ?',
            [$maks, $maks, now()->addMinutes((int) config('autentikasi.penguncian.durasi_menit'))->toIso8601String(), $pengguna->id],
        );

        if ($pengguna->refresh()->terkunci()) {
            $this->audit->catat('autentikasi.terkunci', $pengguna->id, 'akses.pengguna', $pengguna->id, [
                'terkunci_sampai' => $pengguna->terkunci_sampai?->toIso8601String(),
            ]);
        }
    }
}
