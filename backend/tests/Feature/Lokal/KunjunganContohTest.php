<?php

namespace Tests\Feature\Lokal;

use App\Core\Instalasi\StatusInstalasi;
use App\Modules\MasterData\Database\Seeders\OrganisasiSintetisSeeder;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\TestCase;

/** `lokal:kunjungan-contoh`: data kunjungan sintetis untuk e2e pendaftaran, hanya APP_ENV=local. */
class KunjunganContohTest extends TestCase
{
    use AksesUji, KlienSpa;

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

    public function test_paket_icd_sintetis_hanya_bila_belum_ada_dan_akun_dokter_ditautkan(): void
    {
        $this->app['env'] = 'local';
        $this->seed(OrganisasiSintetisSeeder::class);
        $akun = $this->buatPengguna(['nama_pengguna' => 'dokter.poli']);
        $this->artisan('lokal:kunjungan-contoh')->assertSuccessful();
        $this->artisan('lokal:kunjungan-contoh')->assertSuccessful();

        $this->assertSame(['contoh-sintetis'], DB::table('inti.paket_referensi')->where('sistem', 'icd10')->pluck('versi')->all());
        $this->assertSame(['ZZC01', 'ZZC02'], DB::table('inti.terminologi')->where('sistem', 'icd10')->orderBy('kode')->pluck('kode')->all());
        $this->assertSame(Pegawai::query()->where('nip', 'SINTETIS-POLI')->value('id'), $akun->fresh()?->pegawai_id);
        // Triase (2.24): satu instrumen sintetis berlabel contoh, tidak berganda saat diulang.
        $this->assertSame(['Instrumen triase contoh'], DB::table('inti.instrumen_triase')->where('kode', 'CONTOH-TRIASE')->pluck('nama')->all());
    }

    public function test_paket_icd_yang_sudah_dimuat_rs_tidak_disentuh(): void
    {
        $this->app['env'] = 'local';
        $this->seed(OrganisasiSintetisSeeder::class);
        DB::table('inti.paket_referensi')->insert(['id' => (string) Str::uuid7(), 'sistem' => 'icd10', 'versi' => 'rs-1', 'sumber' => 'Berkas RS (uji)',
            'jumlah_kode' => 0, 'sidik_isi' => str_repeat('1', 64), 'dimuat_pada' => now()]);
        $this->artisan('lokal:kunjungan-contoh')->assertSuccessful();

        $this->assertSame(['rs-1'], DB::table('inti.paket_referensi')->where('sistem', 'icd10')->pluck('versi')->all());
        $this->assertSame(0, DB::table('inti.terminologi')->where('sistem', 'icd10')->count());
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
