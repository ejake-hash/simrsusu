<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Application\VerifikasiMfa;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\KlienSpa;
use Tests\TestCase;

/** Kontrak MFA PRD 10.3.2 (tiket 1.1b), lewat alur SPA apa adanya. */
class MfaTest extends TestCase
{
    use KlienSpa;

    private const SANDI = 'kuda-lumping-biru-29';

    private string $berkasLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->berkasLog = storage_path('framework/testing/mfa-'.Str::random(8).'.log');
        config(['logging.default' => 'single', 'logging.channels.single.path' => $this->berkasLog]);
        app('log')->forgetChannel('single');

        $this->mulaiSesiSpa();
    }

    protected function tearDown(): void
    {
        @unlink($this->berkasLog);
        parent::tearDown();
    }

    public function test_akun_wajib_mfa_perlu_kode_dan_belum_masuk_sampai_kode_benar(): void
    {
        [$pengguna, $rahasia] = $this->penggunaMfa();

        $sandi = $this->masuk($pengguna)->assertOk()
            ->assertJsonPath('status', 'perlu_mfa')
            ->assertJsonPath('metode', ['totp', 'kode_cadangan'])
            ->assertJsonMissingPath('pengguna');
        $this->assertEqualsWithDelta(5 * 60, now()->diffInSeconds($sandi->json('kedaluwarsa_pada')), 5);

        $this->minta('GET', '/api/v1/saya')->assertUnauthorized()->assertJsonPath('galat.kode', 'belum_masuk');
        $sesiSebelum = $this->kue[config('session.cookie')];

        $this->kirimKode($sandi->json('tantangan_id'), 'totp', $this->kodeSekarang($rahasia))->assertOk()
            ->assertJsonPath('status', 'masuk')
            ->assertJsonPath('pengguna.id', $pengguna->id)
            ->assertJsonPath('pengguna.mfa_aktif', true);

        $this->assertNotSame($sesiSebelum, $this->kue[config('session.cookie')], 'ID sesi diganti setelah masuk.');
        $this->minta('GET', '/api/v1/saya')->assertOk()->assertJsonPath('data.mfa_aktif', true);
        $this->assertAudit('autentikasi.mfa_berhasil', $pengguna->id);
        $this->assertAudit('autentikasi.masuk', $pengguna->id);
    }

    public function test_daftar_mfa_pertama_kali_lalu_sepuluh_kode_cadangan_sekali_tampil(): void
    {
        $pengguna = Pengguna::factory()->denganSandi(self::SANDI)->create(['wajib_mfa' => true]);

        $tantangan = $this->masuk($pengguna)->assertOk()
            ->assertJsonPath('status', 'perlu_daftar_mfa')
            ->json('tantangan_id');
        $this->minta('GET', '/api/v1/saya')->assertUnauthorized();

        $daftar = $this->minta('POST', '/api/v1/autentikasi/mfa/daftar', ['tantangan_id' => $tantangan])->assertOk();
        $this->assertStringContainsString('no-store', (string) $daftar->headers->get('Cache-Control'));
        $this->assertStringStartsWith('otpauth://totp/', $daftar->json('uri_otpauth'));
        $this->assertStringContainsString('<svg', $daftar->json('qr_svg'));
        parse_str((string) parse_url($daftar->json('uri_otpauth'), PHP_URL_QUERY), $query);
        $rahasia = $query['secret'];

        $konfirmasi = $this->minta('POST', '/api/v1/autentikasi/mfa/daftar/konfirmasi', [
            'tantangan_id' => $tantangan,
            'kode' => $this->kodeSekarang($rahasia),
        ])->assertOk()->assertJsonPath('status', 'masuk')->assertJsonPath('pengguna.mfa_aktif', true);

        $kode = $konfirmasi->json('kode_cadangan');
        $this->assertCount(10, array_unique($kode));
        $this->assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $kode[0]);
        $this->assertStringContainsString('no-store', (string) $konfirmasi->headers->get('Cache-Control'));

        // Rahasia terenkripsi di DB; kode cadangan hanya sebagai hash Argon2id.
        $mentah = DB::table('akses.pengguna')->where('id', $pengguna->id)->value('mfa_rahasia_enkripsi');
        $this->assertStringNotContainsString($rahasia, $mentah);
        $this->assertSame($rahasia, $pengguna->refresh()->mfa_rahasia_enkripsi);
        $hash = DB::table('akses.kode_cadangan_mfa')->where('pengguna_id', $pengguna->id)->pluck('kode_hash');
        $this->assertCount(10, $hash);
        $this->assertSame('argon2id', password_get_info($hash[0])['algoName']);
        $this->assertAudit('autentikasi.mfa_didaftarkan', $pengguna->id);

        // Tantangan sekali pakai.
        $this->minta('POST', '/api/v1/autentikasi/mfa/daftar/konfirmasi', ['tantangan_id' => $tantangan, 'kode' => '000000'])
            ->assertUnauthorized()->assertJsonPath('galat.kode', 'tantangan_tidak_berlaku');
    }

    public function test_konfirmasi_tanpa_minta_rahasia_dulu_tidak_berlaku(): void
    {
        $pengguna = Pengguna::factory()->denganSandi(self::SANDI)->create(['wajib_mfa' => true]);
        $tantangan = $this->masuk($pengguna)->json('tantangan_id');

        $this->minta('POST', '/api/v1/autentikasi/mfa/daftar/konfirmasi', ['tantangan_id' => $tantangan, 'kode' => '123456'])
            ->assertUnauthorized()->assertJsonPath('galat.kode', 'tantangan_tidak_berlaku');
    }

    public function test_kode_salah_dihitung_bersama_sandi_lalu_terkunci_dengan_retry_after(): void
    {
        [$pengguna, $rahasia] = $this->penggunaMfa();

        // Dua sandi salah, lalu sandi benar: penghitung TIDAK direset sebelum MFA lolos.
        $this->masuk($pengguna, 'sandi-salah-pertama')->assertUnauthorized();
        $this->masuk($pengguna, 'sandi-salah-kedua')->assertUnauthorized();
        $tantangan = $this->masuk($pengguna)->assertJsonPath('status', 'perlu_mfa')->json('tantangan_id');
        $this->assertSame(2, $pengguna->refresh()->percobaan_gagal);

        $this->kirimKode($tantangan, 'totp', '000000')->assertUnauthorized()->assertJsonPath('galat.kode', 'kode_mfa_salah');
        $this->kirimKode($tantangan, 'kode_cadangan', 'aaaaa-aaaaa')->assertUnauthorized()->assertJsonPath('galat.kode', 'kode_mfa_salah');
        $this->kirimKode($tantangan, 'totp', '111111')
            ->assertForbidden()->assertJsonPath('galat.kode', 'akun_terkunci')
            ->assertHeader('Retry-After');
        $this->assertTrue($pengguna->refresh()->terkunci());
        $this->assertAudit('autentikasi.mfa_gagal', $pengguna->id);
        $this->assertAudit('autentikasi.terkunci', $pengguna->id);

        // Tantangan dibuang saat terkunci; kode benar pun tidak berlaku lagi.
        $this->kirimKode($tantangan, 'totp', $this->kodeSekarang($rahasia))
            ->assertUnauthorized()->assertJsonPath('galat.kode', 'tantangan_tidak_berlaku');
    }

    public function test_tantangan_kedaluwarsa_lima_menit(): void
    {
        [$pengguna, $rahasia] = $this->penggunaMfa();
        $tantangan = $this->masuk($pengguna)->json('tantangan_id');

        $this->travel(5)->minutes();
        $this->travel(1)->seconds();

        $this->kirimKode($tantangan, 'totp', $this->kodeSekarang($rahasia))
            ->assertUnauthorized()->assertJsonPath('galat.kode', 'tantangan_tidak_berlaku');
    }

    public function test_tantangan_ditolak_dari_sesi_lain(): void
    {
        [$pengguna, $rahasia] = $this->penggunaMfa();
        $tantangan = $this->masuk($pengguna)->json('tantangan_id');

        $this->mulaiSesiSpa();

        $this->kirimKode($tantangan, 'totp', $this->kodeSekarang($rahasia))
            ->assertUnauthorized()->assertJsonPath('galat.kode', 'tantangan_tidak_berlaku');
    }

    public function test_tantangan_sekali_pakai_dan_kode_totp_tidak_bisa_dipakai_ulang(): void
    {
        [$pengguna, $rahasia] = $this->penggunaMfa();
        $kode = $this->kodeSekarang($rahasia);
        $tantangan = $this->masuk($pengguna)->json('tantangan_id');
        $this->kirimKode($tantangan, 'totp', $kode)->assertOk();

        $this->kirimKode($tantangan, 'totp', $kode)
            ->assertUnauthorized()->assertJsonPath('galat.kode', 'tantangan_tidak_berlaku');

        // Masuk lagi di sesi lain dalam periode yang sama: kode yang sama ditolak.
        $this->mulaiSesiSpa();
        $baru = $this->masuk($pengguna)->json('tantangan_id');
        $this->kirimKode($baru, 'totp', $kode)->assertUnauthorized()->assertJsonPath('galat.kode', 'kode_mfa_salah');
    }

    public function test_kode_periode_sebelumnya_diterima_tetapi_dua_periode_tidak(): void
    {
        [$pengguna, $rahasia] = $this->penggunaMfa();
        $langkah = Totp::langkah(now()->getTimestamp());

        $tantangan = $this->masuk($pengguna)->json('tantangan_id');
        $this->kirimKode($tantangan, 'totp', Totp::kode($rahasia, $langkah - 2))->assertJsonPath('galat.kode', 'kode_mfa_salah');
        $this->kirimKode($tantangan, 'totp', Totp::kode($rahasia, $langkah - 1))->assertOk();
    }

    public function test_kode_cadangan_masuk_sekali_pakai(): void
    {
        [$pengguna] = $this->penggunaMfa();
        $kode = $this->daftarkanKodeCadangan($pengguna);

        $tantangan = $this->masuk($pengguna)->json('tantangan_id');
        $this->kirimKode($tantangan, 'kode_cadangan', strtoupper(str_replace('-', ' ', $kode[3])))->assertOk()
            ->assertJsonPath('status', 'masuk');
        $this->assertAudit('autentikasi.kode_cadangan_dipakai', $pengguna->id);

        $this->mulaiSesiSpa();
        $baru = $this->masuk($pengguna)->json('tantangan_id');
        $this->kirimKode($baru, 'kode_cadangan', $kode[3])->assertUnauthorized()->assertJsonPath('galat.kode', 'kode_mfa_salah');
    }

    public function test_tanpa_kode_cadangan_metode_hanya_totp(): void
    {
        $pengguna = $this->penggunaMfa(kodeCadangan: false)[0];

        $this->masuk($pengguna)->assertJsonPath('metode', ['totp']);
    }

    public function test_akun_tidak_wajib_dari_jaringan_rs_langsung_masuk(): void
    {
        $pengguna = Pengguna::factory()->denganSandi(self::SANDI)->create();

        $this->masuk($pengguna)->assertOk()->assertExactJsonStructure([
            'status', 'pengguna' => ['id', 'nama_pengguna', 'surel', 'login_terakhir_pada', 'mfa_aktif', 'pegawai'],
        ])->assertJsonPath('status', 'masuk');
    }

    public function test_dari_luar_jaringan_rs_wajib_mfa_walau_tidak_ditandai(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $belumDaftar = Pengguna::factory()->denganSandi(self::SANDI)->create();
        [$sudahDaftar] = $this->penggunaMfa(wajib: false);

        $this->masuk($belumDaftar)->assertOk()->assertJsonPath('status', 'perlu_daftar_mfa');
        $this->masuk($sudahDaftar)->assertOk()->assertJsonPath('status', 'perlu_mfa');
    }

    public function test_jaringan_rs_kosong_berarti_semua_wajib_mfa(): void
    {
        config(['autentikasi.mfa.jaringan_rs' => []]);
        $pengguna = Pengguna::factory()->denganSandi(self::SANDI)->create();

        $this->masuk($pengguna)->assertOk()->assertJsonPath('status', 'perlu_daftar_mfa');
    }

    public function test_akun_dinonaktifkan_di_tengah_langkah_kembali_ke_sandi(): void
    {
        [$pengguna, $rahasia] = $this->penggunaMfa();
        $tantangan = $this->masuk($pengguna)->json('tantangan_id');
        $pengguna->forceFill(['status' => 'nonaktif'])->save();

        $this->kirimKode($tantangan, 'totp', $this->kodeSekarang($rahasia))
            ->assertUnauthorized()->assertJsonPath('galat.kode', 'tantangan_tidak_berlaku');
    }

    public function test_log_dan_audit_tanpa_kode_rahasia_maupun_uri_otpauth(): void
    {
        $pengguna = Pengguna::factory()->denganSandi(self::SANDI)->create(['wajib_mfa' => true]);
        $tantangan = $this->masuk($pengguna)->json('tantangan_id');
        $uri = $this->minta('POST', '/api/v1/autentikasi/mfa/daftar', ['tantangan_id' => $tantangan])->json('uri_otpauth');
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $this->minta('POST', '/api/v1/autentikasi/mfa/daftar/konfirmasi', ['tantangan_id' => $tantangan, 'kode' => '000000']);
        $kodeBenar = $this->kodeSekarang($query['secret']);
        $kodeCadangan = $this->minta('POST', '/api/v1/autentikasi/mfa/daftar/konfirmasi', ['tantangan_id' => $tantangan, 'kode' => $kodeBenar])
            ->assertOk()->json('kode_cadangan');

        $audit = DB::table('audit.log_aktivitas')->get()->toJson();
        $log = is_file($this->berkasLog) ? (string) file_get_contents($this->berkasLog) : '';
        foreach ([$audit, $log] as $teks) {
            foreach ([$query['secret'], 'otpauth://', $kodeBenar, $kodeCadangan[0], str_replace('-', '', $kodeCadangan[0])] as $rahasia) {
                $this->assertStringNotContainsString($rahasia, $teks);
            }
        }
        $this->assertAudit('autentikasi.mfa_gagal', $pengguna->id);
    }

    /** @return array{0: Pengguna, 1: string} */
    private function penggunaMfa(bool $wajib = true, bool $kodeCadangan = true): array
    {
        $rahasia = Totp::rahasiaBaru();
        $pengguna = Pengguna::factory()->denganSandi(self::SANDI)->create(['wajib_mfa' => $wajib]);
        $pengguna->forceFill(['mfa_rahasia_enkripsi' => $rahasia, 'mfa_aktif_pada' => now()])->save();
        if ($kodeCadangan) {
            DB::table('akses.kode_cadangan_mfa')->insert([
                'id' => (string) Str::uuid7(),
                'pengguna_id' => $pengguna->id,
                'kode_hash' => Hash::make('tidakdipakai'),
            ]);
        }

        return [$pengguna, $rahasia];
    }

    /** Kode cadangan sungguhan lewat alur daftar ulang di VerifikasiMfa. @return list<string> */
    private function daftarkanKodeCadangan(Pengguna $pengguna): array
    {
        $rahasia = (string) $pengguna->mfa_rahasia_enkripsi;
        $langkah = Totp::langkah(now()->getTimestamp()) - 1;

        return app(VerifikasiMfa::class)
            ->daftarkan($pengguna->refresh(), $rahasia, Totp::kode($rahasia, $langkah));
    }

    private function masuk(Pengguna $pengguna, string $sandi = self::SANDI): TestResponse
    {
        return $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $pengguna->nama_pengguna, 'sandi' => $sandi]);
    }

    private function kirimKode(string $tantangan, string $metode, string $kode): TestResponse
    {
        return $this->minta('POST', '/api/v1/autentikasi/mfa', ['tantangan_id' => $tantangan, 'metode' => $metode, 'kode' => $kode]);
    }

    private function kodeSekarang(string $rahasia): string
    {
        return Totp::kode($rahasia, Totp::langkah(now()->getTimestamp()));
    }

    private function assertAudit(string $aksi, string $penggunaId): void
    {
        $this->assertDatabaseHas('audit.log_aktivitas', ['aksi' => $aksi, 'pengguna_id' => $penggunaId]);
    }
}
