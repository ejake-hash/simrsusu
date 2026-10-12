<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Layar integrasi selesai dibangun (tiket 1.9-fe): Pengaturan → Konfigurasi integrasi dan Integrasi dan Laporan →
 * SATUSEHAT, Bridging BPJS, Log integrasi TERSEDIA.
 */
return new class extends Migration
{
    private const SUB_MENU = ['pengaturan.integrasi', 'integrasi.satusehat', 'integrasi.bpjs', 'integrasi.log'];

    public function up(): void
    {
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        DB::table('akses.menu')->whereIn('kode', self::SUB_MENU)->update(['tersedia' => false, 'diubah_pada' => now()]);
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
