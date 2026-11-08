<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Tiket 2.15-be: izin rawat_jalan.pemeriksaan.sahkan (selesaikan kunjungan). Layar Pemeriksaan TERSEDIA menyusul di 2.15-fe. */
return new class extends Migration
{
    public function up(): void
    {
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        DB::table('akses.izin')->where('kode', 'rawat_jalan.pemeriksaan.sahkan')->delete();
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
