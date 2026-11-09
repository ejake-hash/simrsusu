<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tiket 2.24: sub-menu IGD → Triase dan sub-menu baru Master Data → Instrumen triase (lihat, input, perbaiki) TERSEDIA.
 * Hanya aksi dasar, jadi izin_jenis_aksi_cek tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    /** Sub-menu instrumen triase dan izinnya tetap (bisa sudah diberikan ke peran); hanya ditandai tidak tersedia. */
    public function down(): void
    {
        DB::table('akses.menu')->whereIn('kode', ['igd.triase', 'master_data.instrumen_triase'])->update(['tersedia' => false, 'diubah_pada' => now()]);
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
