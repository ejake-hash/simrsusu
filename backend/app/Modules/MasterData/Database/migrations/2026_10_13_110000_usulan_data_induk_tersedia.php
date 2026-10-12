<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Layar Master Data → Usulan perubahan selesai dibangun (tiket 1.8c-fe): sub-menu master_data.usulan TERSEDIA. */
return new class extends Migration
{
    public function up(): void
    {
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        DB::table('akses.menu')->where('kode', 'master_data.usulan')->update(['tersedia' => false, 'diubah_pada' => now()]);
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
