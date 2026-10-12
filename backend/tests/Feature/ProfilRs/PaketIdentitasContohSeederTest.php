<?php

namespace Tests\Feature\ProfilRs;

use App\Core\ProfilRs\ProfilRs;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PaketIdentitasContohSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\TestCase;

/**
 * Paket identitas lokal/staging dari folder di luar repo lewat layanan profil RS 1.8a (kontras, tipe berkas, audit).
 * Isi paket uji = identitas fiktif "RS Contoh Pendidikan" (docs/brand/contoh).
 */
class PaketIdentitasContohSeederTest extends TestCase
{
    use AksesUji, KlienSpa;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('instalasi.identitas.disk'));
        $this->tugaskan($this->buatPengguna(), $this->superadmin());
        $this->folder = sys_get_temp_dir().'/paket-identitas-uji-'.bin2hex(random_bytes(4));
        mkdir($this->folder);
        copy(base_path('../docs/brand/contoh/logo-rs-contoh.svg'), "$this->folder/logo.svg");
        copy(base_path('../docs/brand/contoh/motif-contoh.svg'), "$this->folder/motif.svg");
        // PNG 1×1 sintetis sebagai foto gedung.
        file_put_contents("$this->folder/gedung.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        config(['instalasi.identitas.paket_contoh_dir' => $this->folder]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("$this->folder/*") ?: []);
        @rmdir($this->folder);
        parent::tearDown();
    }

    public function test_tanpa_folder_dilewati_dan_identitas_tetap_netral(): void
    {
        foreach (['', '/tidak/ada/folder-ini'] as $dir) {
            config(['instalasi.identitas.paket_contoh_dir' => $dir]);
            $this->seed(PaketIdentitasContohSeeder::class);
        }
        $this->assertNull(ProfilRs::satu());
    }

    public function test_paket_dipasang_lewat_layanan_profil_rs_dengan_audit(): void
    {
        $this->tulisProfil(['nama' => 'RS Contoh Pendidikan', 'warna' => ['brand' => '#1f5c99', 'accent' => '#D97706'],
            'kontak_admin' => ['unit' => 'Instalasi SIMRS', 'kontak' => 'ekstensi 0000'],
            'berkas' => ['logo' => 'logo.svg', 'motif' => 'motif.svg', 'foto' => 'gedung.png']]);
        $this->seed(PaketIdentitasContohSeeder::class);

        $profil = ProfilRs::satu();
        $this->assertSame(['RS Contoh Pendidikan', 'Instalasi SIMRS', 'ekstensi 0000'], [$profil->nama, $profil->kontak_admin_unit, $profil->kontak_admin]);
        $this->assertSame(['brand' => '#1F5C99', 'accent' => '#D97706'], $profil->paket_identitas['warna']);
        $this->assertSame(['image/svg+xml', 'image/svg+xml', 'image/png'],
            [$profil->paket_identitas['logo']['tipe'], $profil->paket_identitas['motif']['tipe'], $profil->paket_identitas['foto']['tipe']]);
        Storage::disk((string) config('instalasi.identitas.disk'))->assertExists($profil->paket_identitas['logo']['path']);
        $this->assertSame(4, $profil->revisi);
        $aksi = DB::table('audit.log_aktivitas')->where('subjek_id', $profil->id)->orderBy('urutan')->pluck('aksi')->all();
        $this->assertSame(['inti.profil_rs_diubah', 'inti.profil_rs_berkas_diganti', 'inti.profil_rs_berkas_diganti', 'inti.profil_rs_berkas_diganti'], $aksi);
    }

    public function test_menjalankan_ulang_tidak_menambah_revisi_dan_profil_lama_diperbarui(): void
    {
        (new ProfilRs)->forceFill(['nama' => 'RS Lama Contoh', 'kontak_admin_unit' => 'Unit Lama Contoh', 'kontak_admin' => 'ekstensi 9999',
            'paket_identitas' => ['warna' => ['brand' => '#1F5C99', 'accent' => '#D97706']], 'revisi' => 2])->save();
        $this->tulisProfil(['nama' => 'RS Contoh Pendidikan', 'warna' => ['brand' => '#7A1F5C', 'accent' => '#9BE3C7'], 'berkas' => ['logo' => 'logo.svg']]);
        $this->seed(PaketIdentitasContohSeeder::class);
        $profil = ProfilRs::satu();
        // Tanpa kontak_admin di paket: nilai profil lama dipakai.
        $this->assertSame(['RS Contoh Pendidikan', 'Unit Lama Contoh', 'ekstensi 9999', 4], [$profil->nama, $profil->kontak_admin_unit, $profil->kontak_admin, $profil->revisi]);

        $this->seed(PaketIdentitasContohSeeder::class);
        $this->assertSame(4, ProfilRs::satu()->revisi);
        $this->assertSame(2, DB::table('audit.log_aktivitas')->where('subjek_id', $profil->id)->count());
    }

    public function test_paket_ditolak_tidak_mengubah_profil_sama_sekali(): void
    {
        // Foto SVG ditolak (layar 1.8a: webp, jpg, png) sesudah nama/warna sah: tetap tidak ada yang tersimpan.
        $this->tulisProfil(['nama' => 'RS Contoh Pendidikan', 'warna' => ['brand' => '#1F5C99', 'accent' => '#D97706'], 'berkas' => ['logo' => 'logo.svg', 'foto' => 'motif.svg']]);
        $this->assertGagal('Jenis berkas tidak diizinkan');
        $this->assertNull(ProfilRs::satu());
        $this->assertSame(0, DB::table('audit.log_aktivitas')->where('aksi', 'like', 'inti.profil_rs%')->count());
    }

    public function test_database_seeder_hanya_memanggil_paket_di_local_atau_staging(): void
    {
        $this->tulisProfil(['nama' => 'RS Contoh Pendidikan', 'warna' => ['brand' => '#1F5C99', 'accent' => '#D97706']]);
        $this->seed(DatabaseSeeder::class);
        $this->assertNull(ProfilRs::satu(), 'APP_ENV testing: paket identitas tidak dipasang.');
    }

    public function test_kontras_kurang_ditolak_seperti_layar_profil_rs(): void
    {
        $this->tulisProfil(['nama' => 'RS Contoh Pendidikan', 'warna' => ['brand' => '#9FC5E8', 'accent' => '#D97706']]);
        $this->assertGagal('Kontras warna utama dengan putih');
        $this->assertNull(ProfilRs::satu());
    }

    public function test_berkas_di_luar_folder_atau_tipe_tidak_diizinkan_ditolak(): void
    {
        $this->tulisProfil(['nama' => 'RS Contoh Pendidikan', 'warna' => ['brand' => '#1F5C99', 'accent' => '#D97706'], 'berkas' => ['logo' => '../logo.svg']]);
        $this->assertGagal('tidak ada di dalam folder paket');

        // Symlink di dalam folder yang menunjuk ke luar folder.
        $luar = tempnam(sys_get_temp_dir(), 'luar-paket');
        copy("$this->folder/logo.svg", $luar);
        symlink($luar, "$this->folder/tautan.svg");
        $this->tulisProfil(['nama' => 'RS Contoh Pendidikan', 'warna' => ['brand' => '#1F5C99', 'accent' => '#D97706'], 'berkas' => ['logo' => 'tautan.svg']]);
        try {
            $this->assertGagal('tidak ada di dalam folder paket');
        } finally {
            unlink($luar);
        }

        // Foto tidak boleh SVG (layar 1.8a: webp, jpg, png).
        $this->tulisProfil(['nama' => 'RS Contoh Pendidikan', 'warna' => ['brand' => '#1F5C99', 'accent' => '#D97706'], 'berkas' => ['foto' => 'logo.svg']]);
        $this->assertGagal('Jenis berkas tidak diizinkan');
    }

    public function test_profil_json_tidak_lengkap_ditolak_dan_production_menolak(): void
    {
        $this->tulisProfil(['nama' => 'RS Contoh Pendidikan', 'warna' => ['brand' => 'biru', 'accent' => '#D97706']]);
        $this->assertGagal('"warna.brand" wajib berbentuk #RRGGBB');

        // Langsung lewat container: db:seed di production meminta konfirmasi interaktif.
        $this->app['env'] = 'production';
        $this->expectExceptionMessage('ditolak berjalan di production');
        $this->app->call([new PaketIdentitasContohSeeder, 'run']);
    }

    /** @param  array<string, mixed>  $isi */
    private function tulisProfil(array $isi): void
    {
        file_put_contents("$this->folder/profil.json", (string) json_encode($isi));
    }

    private function assertGagal(string $pesan): void
    {
        try {
            $this->seed(PaketIdentitasContohSeeder::class);
            $this->fail('Seeder seharusnya menolak paket ini.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($pesan, $e->getMessage());
        }
    }
}
