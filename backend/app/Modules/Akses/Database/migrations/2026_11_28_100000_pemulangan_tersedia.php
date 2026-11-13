<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tiket 2.40 FE: layar Rawat Inap → Pemulangan dan → Rencana pulang (satu layar) serta Master Data → Daftar periksa pulang
 * sudah dibangun, jadi ketiga sub-menu TERSEDIA. Sub-menu, izin, dan aksi `sahkan` sudah dipasang migrasi pemulangan
 * (2026_11_27_100000); izin_jenis_aksi_cek tidak berubah.
 */
return new class extends Migration
{
    private const KODE = ['rawat_inap.pemulangan', 'rawat_inap.rencana_pulang', 'master_data.daftar_periksa_pulang'];

    public function up(): void
    {
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    /** Sub-menu dan izinnya tetap (bisa sudah diberikan ke peran); hanya ditandai tidak tersedia. */
    public function down(): void
    {
        DB::table('akses.menu')->whereIn('kode', self::KODE)->update(['tersedia' => false, 'diubah_pada' => now()]);
        app(CacheHakAkses::class)->lupakanSemua();
    }
};
