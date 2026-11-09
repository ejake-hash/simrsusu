<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;

/**
 * Tiket 2.26: sub-menu baru IGD → Disposisi dan Master Data → Matriks perpindahan (lihat, input, perbaiki). Hanya aksi
 * dasar, jadi izin_jenis_aksi_cek tidak berubah. igd.disposisi.input bersyarat SIP/SPK berlaku (IZIN_KLINIS, lapis 5).
 * Halamannya menyusul di PR FE (belum TERSEDIA).
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
