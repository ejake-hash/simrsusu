<?php

namespace Tests\Feature\Lokal;

use App\Core\Lokal\PerintahLokal;
use App\Modules\Akses\Database\Seeders\AkunSintetisSeeder;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Ttd\Domain\AturanPin;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Tiket 1.16: `lokal:siapkan-env` dan `lokal:kode-mfa`. */
class SiapkanEnvTest extends TestCase
{
    private string $folder;

    private string $homeAsli;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = sys_get_temp_dir().'/siapkan-env-'.bin2hex(random_bytes(6));
        mkdir($this->folder.'/home', 0700, true);
        $this->homeAsli = (string) getenv('HOME');
        putenv('HOME='.$this->folder.'/home');
        $this->app->useEnvironmentPath($this->folder);
    }

    protected function tearDown(): void
    {
        putenv('HOME='.$this->homeAsli);
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    private function env(): string
    {
        return $this->folder.'/.env';
    }

    /** @return array<string, string|null> */
    private function nilai(): array
    {
        return Dotenv::parse((string) file_get_contents($this->env()));
    }

    /** @return list<string> */
    private function cadangan(): array
    {
        return glob($this->folder.'/home/.karsitara/cadangan-env/*.env') ?: [];
    }

    public function test_env_kosong_dibuat_dari_contoh_dengan_kunci_dan_rahasia_yang_sah(): void
    {
        $log = Log::spy();

        $this->artisan('lokal:siapkan-env')->assertSuccessful();

        $env = $this->nilai();
        $contoh = Dotenv::parse((string) file_get_contents(base_path('.env.example')));
        $this->assertSame(array_keys($contoh), array_keys($env), 'Urutan dan kunci sama dengan .env.example.');
        foreach (['APP_KEY', 'TTD_KUNCI', 'KREDENSIAL_KUNCI'] as $kunci) {
            $this->assertMatchesRegularExpression('/^base64:/', (string) $env[$kunci]);
            $this->assertSame(32, strlen((string) base64_decode(substr((string) $env[$kunci], 7), true)), $kunci);
        }
        $this->assertNotSame($env['TTD_KUNCI'], $env['KREDENSIAL_KUNCI']);
        foreach (AkunSintetisSeeder::RAHASIA_MFA as $kunciConfig) {
            $kunci = strtoupper($kunciConfig);
            $this->assertArrayHasKey($kunci, $contoh, "{$kunci} harus ada di .env.example (nama = kunci config huruf besar).");
            $this->assertSame(20, strlen(Totp::base32Dekode((string) $env[$kunci])));
        }
        $this->assertFalse(AturanPin::lemah((string) $env['AKUN_SINTETIS_TTD_PIN']));
        $this->assertTrue(Validator::make(['s' => $env['AKUN_SINTETIS_SANDI']], ['s' => ['required', Password::defaults()]])->passes());
        $this->assertSame('127.0.0.1/32,::1/128', $env['AUTENTIKASI_MFA_JARINGAN_RS']);
        $this->assertSame('true', $env['INSTALASI_DEMO']);
        $this->assertSame('true', $env['INTEGRASI_SIMULATOR']);
        $this->assertSame('local', $env['APP_ENV']);
        $this->assertSame(0600, fileperms($this->env()) & 0777);
        $this->assertSame([], $this->cadangan(), 'Tidak ada yang dicadangkan bila .env belum ada.');

        // Rahasia hanya ke .env dan terminal.
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $metode) {
            $log->shouldNotHaveReceived($metode);
        }
    }

    public function test_dijalankan_dua_kali_tidak_mengubah_apa_pun(): void
    {
        $this->artisan('lokal:siapkan-env', ['--kemudahan-uji' => true])->assertSuccessful();
        $pertama = (string) file_get_contents($this->env());

        $this->artisan('lokal:siapkan-env', ['--kemudahan-uji' => true])
            ->expectsOutput('.env sudah lengkap; tidak ada yang diubah.')
            ->doesntExpectOutputToContain('tidak ditimpa')
            ->assertSuccessful();

        $this->assertSame($pertama, file_get_contents($this->env()));
        $this->assertSame([], $this->cadangan());
    }

    public function test_env_lama_hanya_ditambah_dan_dicadangkan_di_luar_repo(): void
    {
        $lama = "APP_ENV=local\nAPP_KEY=base64:bGFtYS1rdW5jaS1hcGxpa2FzaS1zaW50ZXRpcy0zMg==\nTTD_KUNCI=\nINSTALASI_DEMO=false\nSESSION_LIFETIME=120\n";
        file_put_contents($this->env(), $lama);

        $this->artisan('lokal:siapkan-env', ['--kemudahan-uji' => true])
            ->expectsOutputToContain('Sudah terisi, tidak ditimpa: INSTALASI_DEMO, SESSION_LIFETIME.')
            ->assertSuccessful();

        $env = $this->nilai();
        $this->assertSame('base64:bGFtYS1rdW5jaS1hcGxpa2FzaS1zaW50ZXRpcy0zMg==', $env['APP_KEY']);
        $this->assertSame('false', $env['INSTALASI_DEMO']);
        $this->assertSame('120', $env['SESSION_LIFETIME']);
        $this->assertStringStartsWith('base64:', (string) $env['TTD_KUNCI'], 'Kunci kosong diisi.');
        $this->assertSame('480', $env['AUTENTIKASI_BATAS_DIAM_MENIT']);
        $this->assertArrayHasKey('DB_DATABASE', $env, 'Kunci .env.example yang belum ada ditambahkan.');
        $this->assertStringContainsString("# LOKAL: kemudahan uji (lokal:siapkan-env --kemudahan-uji); jangan dipakai di server.\nAUTENTIKASI_BATAS_DIAM_MENIT=480", (string) file_get_contents($this->env()));

        $cadangan = $this->cadangan();
        $this->assertCount(1, $cadangan);
        $this->assertSame($lama, file_get_contents($cadangan[0]));
        $this->assertSame(0600, fileperms($cadangan[0]) & 0777);
        $this->assertStringStartsNotWith(dirname(base_path()), $cadangan[0]);
    }

    public function test_nilai_dibaca_seperti_phpdotenv(): void
    {
        // Komentar sebaris = kosong; kunci ganda = yang terakhir berlaku; baris contoh berinterpolasi disalin apa adanya.
        file_put_contents($this->env(), "APP_ENV=local\nAPP_KEY=base64:bGFtYS1rdW5jaS1hcGxpa2FzaS1zaW50ZXRpcy0zMg==\nAPP_KEY=\nTTD_KUNCI= # isi nanti\nAPP_NAME=\"Karsitara SIMRS\"\n");

        $this->artisan('lokal:siapkan-env')->assertSuccessful();

        $env = $this->nilai();
        $this->assertStringStartsWith('base64:', (string) $env['APP_KEY']);
        $this->assertNotSame('base64:bGFtYS1rdW5jaS1hcGxpa2FzaS1zaW50ZXRpcy0zMg==', $env['APP_KEY']);
        $this->assertStringStartsWith('base64:', (string) $env['TTD_KUNCI']);
        $this->assertSame('Karsitara SIMRS', $env['MAIL_FROM_NAME']);
        $this->assertStringContainsString("\nMAIL_FROM_NAME=\"\${APP_NAME}\"\n", (string) file_get_contents($this->env()));

        // Nilai berkutip banyak baris tidak dipotong.
        file_put_contents($this->env(), "APP_ENV=local\nINSTALASI_DEMO=\"banyak\nbaris\"\n");
        $this->artisan('lokal:siapkan-env')->assertSuccessful();
        $this->assertSame("banyak\nbaris", $this->nilai()['INSTALASI_DEMO']);
    }

    public function test_paket_identitas_tidak_menimpa_nilai_terisi(): void
    {
        mkdir($this->folder.'/p1');
        mkdir($this->folder.'/p2');
        $this->artisan('lokal:siapkan-env', ['--paket-identitas' => $this->folder.'/p1'])->assertSuccessful();

        $this->artisan('lokal:siapkan-env', ['--paket-identitas' => $this->folder.'/p2'])
            ->expectsOutputToContain('Sudah terisi, tidak ditimpa: PAKET_IDENTITAS_CONTOH_DIR.')
            ->assertSuccessful();

        $this->assertSame(realpath($this->folder.'/p1'), $this->nilai()['PAKET_IDENTITAS_CONTOH_DIR']);
    }

    public function test_paket_identitas_harus_di_luar_repo_dan_jalur_berspasi_dikutip(): void
    {
        $this->artisan('lokal:siapkan-env', ['--paket-identitas' => base_path('..')])
            ->expectsOutput('Folder paket identitas harus di luar repo (repo publik).')
            ->assertFailed();
        $this->assertFileDoesNotExist($this->env());

        $paket = $this->folder.'/Paket RS $contoh';
        mkdir($paket);
        $this->artisan('lokal:siapkan-env', ['--paket-identitas' => $paket])->assertSuccessful();

        $this->assertSame(realpath($paket), $this->nilai()['PAKET_IDENTITAS_CONTOH_DIR']);
    }

    public function test_ditolak_bila_production(): void
    {
        $lama = "APP_ENV=production\nAPP_KEY=\n";
        file_put_contents($this->env(), $lama);

        $this->artisan('lokal:siapkan-env')->assertFailed();
        $this->assertSame($lama, file_get_contents($this->env()));
        $this->assertFalse(PerintahLokal::diizinkan($this->app));

        unlink($this->env());
        $this->assertTrue(PerintahLokal::diizinkan($this->app));
        $this->app['env'] = 'production';
        file_put_contents($this->env(), "APP_ENV=local\n");
        $this->assertFalse(PerintahLokal::diizinkan($this->app), 'Lingkungan aplikasi production menang atas isi berkas.');
    }

    public function test_perintah_lokal_tidak_terdaftar_di_production(): void
    {
        $proses = new Process(['php', 'artisan', 'list', '--raw'], base_path(), ['APP_ENV' => 'production', 'APP_DEBUG' => 'false']);
        $proses->mustRun();

        $this->assertStringContainsString('akses:superadmin-pertama', $proses->getOutput());
        $this->assertStringNotContainsString('lokal:', $proses->getOutput());
    }

    public function test_kode_mfa_hanya_lokal_dan_sama_dengan_totp(): void
    {
        $rahasia = Totp::rahasiaBaru();
        config(['autentikasi.akun_sintetis_superadmin_mfa_rahasia' => $rahasia]);

        $this->artisan('lokal:kode-mfa', ['nama_pengguna' => 'contoh.superadmin'])->assertFailed();

        $this->app['env'] = 'local';
        $this->travelTo(now()->setTime(10, 0, 5));
        $this->artisan('lokal:kode-mfa', ['nama_pengguna' => 'contoh.superadmin'])
            ->expectsOutputToContain(Totp::kode($rahasia, Totp::langkah(now()->getTimestamp())))
            ->assertSuccessful();
        $this->artisan('lokal:kode-mfa', ['nama_pengguna' => 'contoh.admisi'])->assertFailed();
    }
}
