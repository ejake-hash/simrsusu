<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Tiket 2.21: layar Tinjauan akses darurat TERSEDIA di menu Rekam Medis. */
return new class extends Migration
{
    public function up(): void
    {
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        DB::table('akses.menu')->where('kode', 'rekam_medis.akses_darurat')->update(['tersedia' => false, 'diubah_pada' => now()]);
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
