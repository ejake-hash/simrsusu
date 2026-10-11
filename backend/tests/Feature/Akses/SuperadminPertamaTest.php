<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Application\KebijakanMfa;
use App\Modules\Akses\Application\SuperadminPertama;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\Support\KlienSpa;
use Tests\TestCase;

/** php artisan akses:superadmin-pertama (tiket 1.1c, FR-54-08 langkah 1). */
class SuperadminPertamaTest extends TestCase
{
    use KlienSpa;

    private const SANDI = 'kuda-lumping-biru-29';

    private string $berkasLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->berkasLog = storage_path('framework/testing/superadmin-'.Str::random(8).'.log');
        config(['logging.default' => 'single', 'logging.channels.single.path' => $this->berkasLog]);
        app('log')->forgetChannel('single');
    }

    protected function tearDown(): void
    {
        @unlink($this->berkasLog);
        parent::tearDown();
    }

    public function test_membuat_superadmin_bernama_dengan_mfa_aktif_dan_kode_cadangan(): void
    {
        $this->jalankan('admin.satu', 'Contoh Admin Satu')
            ->expectsOutputToContain('Superadmin admin.satu dibuat dengan MFA aktif.')
            ->expectsOutputToContain('otpauth://totp/')
            ->expectsOutputToContain('Kode cadangan')
            ->expectsOutputToContain('Buat 1 superadmin lagi')
            ->assertSuccessful();

        $pengguna = Pengguna::query()->where('nama_pengguna', 'admin.satu')->firstOrFail();
        $this->assertTrue($pengguna->aktif());
        // Wajib MFA karena peran superadmin (tiket 1.2a), bukan karena kolom akun.
        $this->assertFalse($pengguna->wajib_mfa);
        $this->assertSame([MenuBawaan::PERAN_SUPERADMIN], PenggunaPeran::query()->berlaku()->where('pengguna_id', $pengguna->id)
            ->with('peran')->get()->pluck('peran.kode')->all());
        $this->assertTrue(app(KebijakanMfa::class)->wajib($pengguna, '127.0.0.1'));
        $this->assertTrue($pengguna->mfaAktif());
        $this->assertSame('Contoh Admin Satu', DB::table('sdm.pegawai')->where('id', $pengguna->pegawai_id)->value('nama'));
        $this->assertSame(10, DB::table('akses.kode_cadangan_mfa')->where('pengguna_id', $pengguna->id)->whereNull('dibatalkan_pada')->count());
        $this->assertDatabaseHas('audit.log_aktivitas', ['aksi' => SuperadminPertama::AKSI_AUDIT, 'subjek_id' => $pengguna->id]);
        $this->assertDatabaseHas('audit.log_aktivitas', ['aksi' => 'autentikasi.mfa_didaftarkan', 'pengguna_id' => $pengguna->id]);
        $this->assertSame(1, app(SuperadminPertama::class)->jumlahAktif());

        // Bisa langsung masuk dari luar jaringan RS dengan TOTP, karena MFA sudah terdaftar.
        $this->mulaiSesiSpa();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $challenge = $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => 'admin.satu', 'sandi' => self::SANDI])
            ->assertOk()->assertJsonPath('status', 'perlu_mfa')->json('challenge_id');
        $rahasia = (string) $pengguna->mfa_rahasia_enkripsi;
        $this->minta('POST', '/api/v1/autentikasi/mfa', [
            'challenge_id' => $challenge,
            'metode' => 'totp',
            'kode' => Totp::kode($rahasia, Totp::langkah(now()->getTimestamp())),
        ])->assertOk()->assertJsonPath('status', 'masuk');
    }

    public function test_menolak_superadmin_ketiga_tanpa_bertanya(): void
    {
        $this->buatLewatLayanan('admin.satu');
        $this->buatLewatLayanan('admin.dua');

        $this->artisan('akses:superadmin-pertama', ['--nama-pengguna' => 'admin.tiga', '--nama' => 'Contoh Admin Tiga'])
            ->expectsOutputToContain('Sudah ada 2 superadmin aktif')
            ->assertFailed();

        $this->assertDatabaseMissing('akses.pengguna', ['nama_pengguna' => 'admin.tiga']);
        $this->assertSame(2, app(SuperadminPertama::class)->jumlahAktif());
    }

    public function test_superadmin_nonaktif_tidak_dihitung(): void
    {
        $this->buatLewatLayanan('admin.satu')->forceFill(['status' => 'nonaktif'])->save();
        $this->buatLewatLayanan('admin.dua');

        $this->jalankan('admin.tiga', 'Contoh Admin Tiga')->assertSuccessful();
    }

    public function test_akun_biasa_ber_mfa_tidak_dihitung_sebagai_superadmin(): void
    {
        Pengguna::factory()->count(2)->create(['wajib_mfa' => true]);

        $this->assertSame(0, app(SuperadminPertama::class)->jumlahAktif());
    }

    public function test_layanan_menolak_bila_sudah_dua(): void
    {
        $this->buatLewatLayanan('admin.satu');
        $this->buatLewatLayanan('admin.dua');

        $this->expectException(\RuntimeException::class);
        $this->buatLewatLayanan('admin.tiga');
    }

    public function test_isian_tidak_valid_tidak_membuat_apa_pun(): void
    {
        $this->jalankan('admin.satu', 'Contoh Admin Satu', sandi: 'pendek')
            ->expectsOutputToContain('sandi')
            ->assertFailed();
        $this->jalankan('admin.satu', 'Contoh Admin Satu', ulangan: 'kuda-lumping-biru-30')
            ->expectsOutputToContain('Ulangan kata sandi tidak sama.')
            ->assertFailed();

        Pengguna::factory()->create(['nama_pengguna' => 'Sudah.Ada']);
        $this->jalankan('sudah.ada', 'Contoh Admin')
            ->expectsOutputToContain('Nama pengguna sudah dipakai.')
            ->assertFailed();

        $this->assertDatabaseMissing('akses.pengguna', ['nama_pengguna' => 'admin.satu']);
        $this->assertDatabaseMissing('audit.log_aktivitas', ['aksi' => SuperadminPertama::AKSI_AUDIT]);
    }

    public function test_tetap_berjalan_walau_jaringan_rs_belum_diisi(): void
    {
        config(['autentikasi.mfa.jaringan_rs' => []]);

        $this->jalankan('admin.satu', 'Contoh Admin Satu')
            ->expectsOutputToContain('AUTENTIKASI_MFA_JARINGAN_RS masih kosong')
            ->assertSuccessful();
    }

    public function test_rahasia_sandi_dan_kode_tidak_masuk_log_maupun_audit(): void
    {
        $this->jalankan('admin.satu', 'Contoh Admin Satu')->assertSuccessful();

        $pengguna = Pengguna::query()->where('nama_pengguna', 'admin.satu')->firstOrFail();
        $rahasia = (string) $pengguna->mfa_rahasia_enkripsi;
        $audit = DB::table('audit.log_aktivitas')->get()->toJson();
        $log = is_file($this->berkasLog) ? (string) file_get_contents($this->berkasLog) : '';

        foreach ([$audit, $log] as $teks) {
            foreach ([$rahasia, 'otpauth://', self::SANDI] as $terlarang) {
                $this->assertStringNotContainsString($terlarang, $teks);
            }
        }
    }

    private function jalankan(string $namaPengguna, string $nama, string $sandi = self::SANDI, ?string $ulangan = null): PendingCommand
    {
        return $this->artisan('akses:superadmin-pertama', ['--nama-pengguna' => $namaPengguna, '--nama' => $nama])
            ->expectsQuestion('Kata sandi', $sandi)
            ->expectsQuestion('Ulangi kata sandi', $ulangan ?? $sandi);
    }

    private function buatLewatLayanan(string $namaPengguna): Pengguna
    {
        return app(SuperadminPertama::class)->buat($namaPengguna, 'Contoh '.$namaPengguna, null, null, self::SANDI)['pengguna'];
    }
}
