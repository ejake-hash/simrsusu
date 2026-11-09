<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;

/**
 * Tiket 2.25: sub-menu baru Master Data → Area & batas papan IGD (lihat, input, perbaiki). Izin igd.papan.* sudah ada
 * sejak pohon menu 1.2; endpoint papan memakainya. Hanya aksi dasar, jadi izin_jenis_aksi_cek tidak berubah. Halamannya
 * menyusul di PR FE (belum TERSEDIA).
 */
return new class extends Migration
{
    public function up(): void
    {
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    /** Sub-menu dan izinnya tetap (bisa sudah diberikan ke peran); maju saja. */
    public function down(): void {}
};
