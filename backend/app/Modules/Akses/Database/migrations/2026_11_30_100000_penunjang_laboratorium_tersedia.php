<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tiket 2.30 FE: layar Penunjang → Laboratorium (daftar kerja lab, ambil sampel, terima spesimen) sudah dibangun, jadi
 * sub-menu TERSEDIA. Sub-menu, izin, dan aksi `verifikasi` sudah dipasang migrasi lab (2026_11_29_100000).
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
        DB::table('akses.menu')->where('kode', 'penunjang.laboratorium')->update(['tersedia' => false, 'diubah_pada' => now()]);
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
