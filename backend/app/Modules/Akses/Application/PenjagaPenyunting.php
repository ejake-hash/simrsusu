<?php

namespace App\Modules\Akses\Application;

use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use Illuminate\Http\Request;

/**
 * Batas penyunting akses yang tidak bisa diberikan lewat izin (PRD 9.2.4, 9.3): tidak mengubah akun
 * sendiri, tidak mengubah isi peran yang ia pegang, dan perubahan peran memakai versi (If-Match).
 */
final class PenjagaPenyunting
{
    /**
     * Semua perubahan akses akun: bukan akun sendiri, dan akun pemegang peran superadmin hanya diubah
     * oleh superadmin lain (PRD 9.2.4), bukan oleh admin yang izinnya lebih sempit.
     */
    public function pastikanBolehMengubahAkun(Pengguna $penyunting, Pengguna $akun): void
    {
        if ($penyunting->id === $akun->id) {
            throw new ApiError(403, 'diri_sendiri', 'Perubahan akses akun sendiri dilakukan superadmin lain.');
        }
        // Akun: termasuk penugasan yang akan berlaku. Penyunting: hanya yang berlaku sekarang.
        if ($this->superadmin($akun, termasukMasaDepan: true) && ! $this->superadmin($penyunting, termasukMasaDepan: false)) {
            throw new ApiError(403, 'akun_superadmin', 'Akses akun superadmin hanya diubah oleh superadmin lain.');
        }
    }

    public function superadmin(Pengguna $pengguna, bool $termasukMasaDepan): bool
    {
        $query = PenggunaPeran::query()->where('pengguna_id', $pengguna->id)
            ->whereHas('peran', fn ($peran) => $peran->where('kode', MenuBawaan::PERAN_SUPERADMIN));

        return ($termasukMasaDepan ? $query->masihAktif() : $query->berlaku())->exists();
    }

    /** Penugasan aktif yang sedang atau akan berlaku; yang sudah lewat masanya tidak dihitung. */
    public function memegangPeran(Pengguna $pengguna, string $peranId): bool
    {
        return PenggunaPeran::query()->where('pengguna_id', $pengguna->id)->where('peran_id', $peranId)->masihAktif()->exists();
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
        return IfMatch::versi($request);
    }
}
