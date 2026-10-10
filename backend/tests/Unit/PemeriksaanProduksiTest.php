<?php

namespace Tests\Unit;

use App\Core\PemeriksaanProduksi;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PemeriksaanProduksiTest extends TestCase
{
    public function test_production_menolak_app_debug_true(): void
    {
        $this->expectExceptionObject(new RuntimeException('APP_DEBUG harus false di production.'));
        PemeriksaanProduksi::periksa('production', true, true, fn () => false);
    }

    public function test_production_menolak_telescope_dan_debugbar(): void
    {
        foreach (PemeriksaanProduksi::ALAT_DEBUG as $alat) {
            try {
                PemeriksaanProduksi::periksa('production', false, true, fn (string $kelas) => $kelas === $alat);
                $this->fail("{$alat} seharusnya ditolak.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($alat, $e->getMessage());
            }
        }
    }

    public function test_production_menolak_cookie_tanpa_secure(): void
    {
        $this->expectException(RuntimeException::class);
        PemeriksaanProduksi::periksa('production', false, false, fn () => false);
    }

    public function test_production_yang_benar_lolos_dan_lokal_boleh_debug(): void
    {
        PemeriksaanProduksi::periksa('production', false, true, fn () => false);
        PemeriksaanProduksi::periksa('local', true, false, fn () => true);
        $this->addToAssertionCount(2);
    }
}
