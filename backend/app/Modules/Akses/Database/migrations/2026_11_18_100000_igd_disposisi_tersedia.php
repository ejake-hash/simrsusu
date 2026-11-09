<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tiket 2.26 FE: halaman IGD → Disposisi dan Master Data → Matriks perpindahan sudah dibangun, jadi sub-menunya TERSEDIA.
 * Sub-menu dan izinnya sudah dipasang 2026_11_17_120000; hanya aksi dasar, jadi izin_jenis_aksi_cek tidak berubah.
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
        DB::table('akses.menu')->whereIn('kode', ['igd.disposisi', 'master_data.matriks_perpindahan'])->update(['tersedia' => false, 'diubah_pada' => now()]);
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
