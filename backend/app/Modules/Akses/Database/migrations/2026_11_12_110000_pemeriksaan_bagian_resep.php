<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Tiket 2.17-be: bagian rawat_jalan.pemeriksaan.resep (lihat, input, sahkan bersyarat SIP lapis 5, batal). */
return new class extends Migration
{
    public function up(): void
    {
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        DB::table('akses.izin')->where('kode', 'like', 'rawat_jalan.pemeriksaan.resep.%')->delete();
        DB::table('akses.menu')->where('kode', 'rawat_jalan.pemeriksaan.resep')->delete();
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
