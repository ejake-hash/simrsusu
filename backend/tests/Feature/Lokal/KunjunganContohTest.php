<?php

namespace Tests\Feature\Lokal;

use App\Core\Instalasi\StatusInstalasi;
use App\Modules\MasterData\Database\Seeders\OrganisasiSintetisSeeder;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $poli = Poliklinik::query()->where('kode', 'POLI-PD')->firstOrFail();
        $this->assertSame('nonaktif', $poli->status);
        $this->assertSame(['nonaktif'], JadwalPraktik::query()->where('poli_id', $poli->id)->distinct()->pluck('status')->all());

        // Dipasang ulang: baris poli yang sama aktif kembali (tidak dihapus saat batal).
        $this->artisan('lokal:kunjungan-contoh')->assertSuccessful();
        $this->assertSame('aktif', $poli->fresh()?->status);
    }

    public function test_batal_tidak_mengosongkan_penanda_siap_dari_wizard(): void
    {
        $this->app['env'] = 'local';
        $this->seed(OrganisasiSintetisSeeder::class);
        DB::table('inti.profil_rs')->exists()
            ? DB::table('inti.profil_rs')->update(['siap_melayani_sejak' => now()])
            : DB::table('inti.profil_rs')->insert(['id' => (string) Str::uuid7(), 'paket_identitas' => '{}', 'siap_melayani_sejak' => now()]);
        $sejak = DB::table('inti.profil_rs')->value('siap_melayani_sejak');

        $this->artisan('lokal:kunjungan-contoh')->assertSuccessful();
        $this->artisan('lokal:kunjungan-contoh', ['--batal' => true])->assertSuccessful();

        $this->assertSame($sejak, DB::table('inti.profil_rs')->value('siap_melayani_sejak'));
    }
}
