<?php

namespace Tests\Feature\Lokal;

use App\Core\Instalasi\StatusInstalasi;
use App\Modules\MasterData\Database\Seeders\OrganisasiSintetisSeeder;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\Sdm\Models\Pegawai;
use Tests\TestCase;

/** `lokal:kunjungan-contoh`: data kunjungan sintetis untuk e2e pendaftaran, hanya APP_ENV=local. */
class KunjunganContohTest extends TestCase
{
    public function test_menyiapkan_jadwal_tiap_hari_dan_penanda_siap_lalu_batal(): void
    {
        $this->artisan('lokal:kunjungan-contoh')->assertFailed();

        $this->app['env'] = 'local';
        $this->seed(OrganisasiSintetisSeeder::class);
        $this->artisan('lokal:kunjungan-contoh')->assertSuccessful();
        $this->artisan('lokal:kunjungan-contoh')->assertSuccessful();

        $dokter = Pegawai::query()->where('nip', 'SINTETIS-POLI')->firstOrFail();
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], JadwalPraktik::query()->where('pegawai_id', $dokter->id)->orderBy('hari')->pluck('hari')->all());
        $this->assertTrue(app(StatusInstalasi::class)->siapMelayani());

        $this->artisan('lokal:kunjungan-contoh', ['--batal' => true])->assertSuccessful();
        $this->assertFalse(app(StatusInstalasi::class)->siapMelayani());
    }
}
