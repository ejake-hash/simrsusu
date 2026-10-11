<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Istilah umum (tiket 1.0g): kode menu data_induk → master_data, rute /data-induk → /master-data, di
 * akses.menu dan akses.izin. UPDATE di tempat (id tetap), sehingga peran_menu, peran_izin, dan
 * pengecualian per akun ikut menunjuk kode baru. Aman diulang; tanpa DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ganti('data_induk', 'master_data', '/data-induk', '/master-data');
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        $this->ganti('master_data', 'data_induk', '/master-data', '/data-induk');
        app(CacheHakAkses::class)->lupakanSemua();
    }

    private function ganti(string $lama, string $baru, string $ruteLama, string $ruteBaru): void
    {
        DB::update(
            'UPDATE akses.menu SET kode = ? || substr(kode, ?), ikon = CASE WHEN ikon = ? THEN ? ELSE ikon END,
                    rute = CASE WHEN rute = ? OR rute LIKE ? THEN ? || substr(rute, ?) ELSE rute END, diubah_pada = now()
              WHERE kode = ? OR kode LIKE ?',
            [$baru, strlen($lama) + 1, $lama, $baru, $ruteLama, $ruteLama.'/%', $ruteBaru, strlen($ruteLama) + 1, $lama, $lama.'.%'],
        );
        DB::update(
            'UPDATE akses.izin SET kode = ? || substr(kode, ?), diubah_pada = now() WHERE kode LIKE ?',
            [$baru, strlen($lama) + 1, $lama.'.%'],
        );
    }
};
