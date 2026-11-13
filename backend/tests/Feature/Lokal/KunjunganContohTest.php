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
        // Papan IGD (2.25): tiga area sintetis berlabel contoh, tidak berganda saat diulang.
        $this->assertSame(['Resusitasi (contoh)', 'Observasi (contoh)', 'Hijau (contoh)'],
            DB::table('inti.area_igd')->where('kode', 'like', 'CONTOH-%')->orderBy('urutan')->pluck('nama')->all());
        $this->assertSame(60, (int) DB::table('inti.aturan_papan_igd')->value('peringatan_lama_igd_menit'));
    }

    public function test_peta_tempat_tidur_contoh_lewat_layanan_status_dan_dipulihkan_saat_diulang(): void
    {
        $this->app['env'] = 'local';
        $this->seed(OrganisasiSintetisSeeder::class);
        $this->artisan('lokal:kunjungan-contoh')->assertSuccessful();

        $status = fn () => DB::table('inti.tempat_tidur')->where('kode', 'like', 'CTHG-%')->orderBy('kode')->pluck('status_saat_ini', 'kode')->all();
        $harap = ['CTHG-2-201-1' => 'terisi', 'CTHG-2-201-2' => 'dibersihkan', 'CTHG-2-201-3' => 'dipesan',
            'CTHG-2-202-1' => 'terisi', 'CTHG-2-202-2' => 'tidak_dipakai', 'CTHG-2-202-3' => 'kosong_siap', 'CTHG-2-202-4' => 'kosong_siap'];
        $this->assertSame($harap, $status());
        // Modul 08 Rawat Inap aktif agar sub-menu Peta tempat tidur berstatus aktif di menu lokal; 05 untuk Pemulangan (2.40), 19 untuk Laboratorium (2.30).
        $this->assertSame(1, DB::table('inti.modul_aktif')->whereNull('unit_organisasi_id')->where('modul_no', 8)->count());
        $this->assertSame(1, DB::table('inti.modul_aktif')->whereNull('unit_organisasi_id')->where('modul_no', 5)->count());
        $this->assertSame(1, DB::table('inti.modul_aktif')->whereNull('unit_organisasi_id')->where('modul_no', 19)->count());
        $riwayat = fn () => DB::table('kunjungan.riwayat_status_tempat_tidur as r')->join('inti.tempat_tidur as tt', 'tt.id', '=', 'r.tempat_tidur_id')
            ->where('tt.kode', 'like', 'CTHG-%')->count();
        // Lima TT berubah dari status awal kosong_siap, masing-masing satu riwayat bersumber petugas.
        $this->assertSame(5, $riwayat());
        $this->assertSame(['petugas'], DB::table('kunjungan.riwayat_status_tempat_tidur')->distinct()->pluck('sumber_kejadian')->all());

        // Spec peta mengubah dibersihkan → kosong_siap; diulang → status contoh dipulihkan, TT tidak berganda.
        DB::table('inti.tempat_tidur')->where('kode', 'CTHG-2-201-2')->update(['status_saat_ini' => 'kosong_siap']);
        $this->artisan('lokal:kunjungan-contoh')->assertSuccessful();
        $this->assertSame($harap, $status());
        $this->assertSame(6, $riwayat());
        $this->assertSame(['Bangsal Melati (contoh)'], DB::table('inti.bangsal')->where('kode', 'CONTOH-MELATI')->pluck('nama')->all());
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
