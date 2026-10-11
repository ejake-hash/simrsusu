<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use Illuminate\Database\Migrations\Migration;
use Tests\TestCase;

/**
 * Jalur konversi migrasi 2026_10_11_130100 (tiket 1.0g). Di DB baru kodenya sudah master_data, jadi
 * jalur UPDATE diuji dengan awalan sintetis yang sama bentuknya (memuat `_`).
 */
class MigrasiKodeMenuTest extends TestCase
{
    public function test_hanya_awalan_persis_yang_diganti_dan_aman_diulang(): void
    {
        $grup = Menu::query()->where('kode', 'tata_kelola')->value('id');
        $menu = Menu::query()->create(['induk_id' => $grup, 'tingkat' => 'menu', 'kode' => 'uji_lama', 'label' => 'Uji', 'ikon' => 'uji_lama', 'rute' => '/uji-lama']);
        $sub = Menu::query()->create(['induk_id' => $menu->id, 'tingkat' => 'sub_menu', 'kode' => 'uji_lama.satu', 'label' => 'Satu', 'rute' => '/uji-lama/satu']);
        // Pengecoh: `_` pada LIKE cocok dengan karakter apa pun, dan rute berawalan sama tanpa garis miring.
        $pengecoh = Menu::query()->create(['induk_id' => $menu->id, 'tingkat' => 'sub_menu', 'kode' => 'ujiXlama.dua', 'label' => 'Dua', 'rute' => '/uji-lamaan']);
        $izin = Izin::query()->create(['kode' => 'uji_lama.satu.lihat', 'menu_id' => $sub->id, 'jenis_aksi' => 'lihat']);
        $izinPengecoh = Izin::query()->create(['kode' => 'ujiXlama.dua.lihat', 'menu_id' => $pengecoh->id, 'jenis_aksi' => 'lihat']);

        $this->ganti('uji_lama', 'uji_baru', '/uji-lama', '/uji-baru');
        $this->ganti('uji_lama', 'uji_baru', '/uji-lama', '/uji-baru');

        $this->assertSame(['uji_baru', 'uji_baru', '/uji-baru'], [$menu->refresh()->kode, $menu->ikon, $menu->rute]);
        $this->assertSame(['uji_baru.satu', '/uji-baru/satu'], [$sub->refresh()->kode, $sub->rute]);
        $this->assertSame('uji_baru.satu.lihat', $izin->refresh()->kode);
        $this->assertSame(['ujiXlama.dua', '/uji-lamaan'], [$pengecoh->refresh()->kode, $pengecoh->rute]);
        $this->assertSame('ujiXlama.dua.lihat', $izinPengecoh->refresh()->kode);

        // down(): arah sebaliknya memakai fungsi yang sama.
        $this->ganti('uji_baru', 'uji_lama', '/uji-baru', '/uji-lama');
        $this->assertSame(['uji_lama.satu', '/uji-lama/satu'], [$sub->refresh()->kode, $sub->rute]);
        $this->assertSame('ujiXlama.dua', $pengecoh->refresh()->kode);
    }

    private function ganti(string $lama, string $baru, string $ruteLama, string $ruteBaru): void
    {
        /** @var Migration $migrasi */
        $migrasi = require base_path('app/Modules/Akses/Database/migrations/2026_10_11_130100_ganti_kode_menu_master_data.php');
        \Closure::bind(fn () => $this->ganti($lama, $baru, $ruteLama, $ruteBaru), $migrasi, $migrasi::class)();
    }
}
