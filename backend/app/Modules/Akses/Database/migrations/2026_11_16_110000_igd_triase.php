<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Tiket 2.24: sub-menu IGD → Triase (lihat, input, perbaiki) TERSEDIA. Aksi izinnya sudah ada di izin_jenis_aksi_cek. */
return new class extends Migration
{
    public function up(): void
    {
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        DB::table('akses.menu')->where('kode', 'igd.triase')->update(['tersedia' => false, 'diubah_pada' => now()]);
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
