<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tiket 2.25 FE: halaman IGD → Papan pasien IGD dan Master Data → Area & batas papan IGD sudah dibangun, jadi sub-menunya
 * TERSEDIA. Sub-menu dan izinnya sudah dipasang (igd.papan sejak pohon menu 1.2, master_data.papan_igd di
 * 2026_11_19_120000); hanya aksi dasar, jadi izin_jenis_aksi_cek tidak berubah.
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
        DB::table('akses.menu')->whereIn('kode', ['igd.papan', 'master_data.papan_igd'])->update(['tersedia' => false, 'diubah_pada' => now()]);
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
