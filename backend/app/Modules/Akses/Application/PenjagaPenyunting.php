<?php

namespace App\Modules\Akses\Application;

use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use Illuminate\Http\Request;

/**
 * Batas penyunting akses yang tidak bisa diberikan lewat izin (PRD 9.2.4, 9.3): tidak mengubah akun
 * sendiri, tidak mengubah isi peran yang ia pegang, dan perubahan peran memakai versi (If-Match).
 */
final class PenjagaPenyunting
{
    public function pastikanBukanDiriSendiri(Pengguna $penyunting, Pengguna $akun): void
    {
        if ($penyunting->id === $akun->id) {
            throw new ApiError(403, 'diri_sendiri', 'Perubahan akses akun sendiri dilakukan superadmin lain.');
        }
    }

    /** Penugasan aktif yang sedang atau akan berlaku; yang sudah lewat masanya tidak dihitung. */
    public function memegangPeran(Pengguna $pengguna, string $peranId): bool
    {
        return PenggunaPeran::query()->where('pengguna_id', $pengguna->id)->where('peran_id', $peranId)
            ->where('status', 'aktif')
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>', now()->format('Y-m-d H:i:s.uP')))
            ->exists();
    }

    public function pastikanBukanPeranSendiri(Pengguna $penyunting, string $peranId): void
    {
        if ($this->memegangPeran($penyunting, $peranId)) {
            throw new ApiError(403, 'peran_sendiri', 'Peran yang sedang Anda pegang diubah oleh superadmin lain.');
        }
    }

    /** Nilai If-Match tanpa tanda kutip dan awalan W/; tidak ada → 428 if_match_wajib. */
    public static function versiDiminta(Request $request): string
    {
        $nilai = trim((string) $request->header('If-Match'));
        $nilai = trim((string) preg_replace('#^W/#', '', $nilai), '"');
        if ($nilai === '') {
            throw new ApiError(428, 'if_match_wajib', 'Versi data tidak dikirim. Muat ulang halaman lalu coba lagi.');
        }

        return $nilai;
    }
}
