<?php

namespace App\Modules\Akses\Infrastructure;

use App\Modules\Akses\Domain\RelasiPerawatan;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lapis 4 dari akses.relasi_perawatan (tiket 2.6): akun merawat pasien bila pegawai akun itu punya relasi aktif atas
 * pasien tersebut yang berlaku saat ini. Tanpa pasien atau tanpa pegawai = tidak merawat (tolak kecuali diberikan).
 * Dinilai saat diminta, tidak di-cache: relasi berakhir atau dibatalkan langsung menutup data berlabel.
 */
final class RelasiPerawatanTercatat implements RelasiPerawatan
{
    public function merawat(Pengguna $pengguna, ?string $pasienId): bool
    {
        if ($pasienId === null || ! $pengguna->pegawai_id || ! Str::isUuid($pasienId)) {
            return false;
        }
        $sekarang = now()->format('Y-m-d H:i:s.uP');

        return DB::table('akses.relasi_perawatan')
            ->where('pasien_id', $pasienId)->where('pegawai_id', $pengguna->pegawai_id)->where('status', 'aktif')
            ->where('berlaku_dari', '<=', $sekarang)->where('berlaku_sampai', '>', $sekarang)
            ->exists();
    }
}
