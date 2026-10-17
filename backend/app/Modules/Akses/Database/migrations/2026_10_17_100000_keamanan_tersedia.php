<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Layar Pengaturan → Keamanan selesai dibangun (tiket 1.13-fe): sub-menu pengaturan.keamanan TERSEDIA. */
return new class extends Migration
{
    public function up(): void
    {
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        DB::table('akses.menu')->where('kode', 'pengaturan.keamanan')->update(['tersedia' => false, 'diubah_pada' => now()]);
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
