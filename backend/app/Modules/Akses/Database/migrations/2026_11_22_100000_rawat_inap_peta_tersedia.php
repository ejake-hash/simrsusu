<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tiket 2.27 FE: halaman Rawat Inap → Peta tempat tidur sudah dibangun, jadi sub-menunya TERSEDIA. Sub-menu dan izinnya
 * sudah dipasang sejak pohon menu 1.2; hanya aksi dasar, jadi izin_jenis_aksi_cek tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    /** Sub-menu dan izinnya tetap (bisa sudah diberikan ke peran); hanya ditandai tidak tersedia. */
    public function down(): void
    {
        DB::table('akses.menu')->where('kode', 'rawat_inap.peta_tempat_tidur')->update(['tersedia' => false, 'diubah_pada' => now()]);
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
