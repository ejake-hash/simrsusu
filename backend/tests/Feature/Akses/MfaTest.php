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

        $this->minta('GET', '/api/v1/saya')->assertUnauthorized()->assertJsonPath('error.kode', 'belum_masuk');
        $sesiSebelum = $this->kue[config('session.cookie')];

        $this->kirimKode($sandi->json('challenge_id'), 'totp', $this->kodeSekarang($rahasia))->assertOk()
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

        $challenge = $this->masuk($pengguna)->assertOk()
            ->assertJsonPath('status', 'perlu_daftar_mfa')
            ->json('challenge_id');
        $this->minta('GET', '/api/v1/saya')->assertUnauthorized();

        $daftar = $this->minta('POST', '/api/v1/autentikasi/mfa/daftar', ['challenge_id' => $challenge])->assertOk();
        $this->assertStringContainsString('no-store', (string) $daftar->headers->get('Cache-Control'));
        $this->assertStringStartsWith('otpauth://totp/', $daftar->json('uri_otpauth'));
        $this->assertStringContainsString('<svg', $daftar->json('qr_svg'));
        parse_str((string) parse_url($daftar->json('uri_otpauth'), PHP_URL_QUERY), $query);
        $rahasia = $query['secret'];

        $konfirmasi = $this->minta('POST', '/api/v1/autentikasi/mfa/daftar/konfirmasi', [
            'challenge_id' => $challenge,
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

        // Challenge sekali pakai.
        $this->minta('POST', '/api/v1/autentikasi/mfa/daftar/konfirmasi', ['challenge_id' => $challenge, 'kode' => '000000'])
            ->assertUnauthorized()->assertJsonPath('error.kode', 'challenge_tidak_berlaku');
    }

    public function test_konfirmasi_tanpa_minta_rahasia_dulu_tidak_berlaku(): void
    {
        $pengguna = Pengguna::factory()->denganSandi(self::SANDI)->create(['wajib_mfa' => true]);
        $challenge = $this->masuk($pengguna)->json('challenge_id');

        $this->minta('POST', '/api/v1/autentikasi/mfa/daftar/konfirmasi', ['challenge_id' => $challenge, 'kode' => '123456'])
            ->assertUnauthorized()->assertJsonPath('error.kode', 'challenge_tidak_berlaku');
    }

    public function test_kode_salah_dihitung_bersama_sandi_lalu_terkunci_dengan_retry_after(): void
    {
        [$pengguna, $rahasia] = $this->penggunaMfa();

        // Dua sandi salah, lalu sandi benar: penghitung TIDAK direset sebelum MFA lolos.
        $this->masuk($pengguna, 'sandi-salah-pertama')->assertUnauthorized();
        $this->masuk($pengguna, 'sandi-salah-kedua')->assertUnauthorized();
        $challenge = $this->masuk($pengguna)->assertJsonPath('status', 'perlu_mfa')->json('challenge_id');
        $this->assertSame(2, $pengguna->refresh()->percobaan_gagal);

        $this->kirimKode($challenge, 'totp', '000000')->assertUnauthorized()->assertJsonPath('error.kode', 'kode_mfa_salah');
        $this->kirimKode($challenge, 'kode_cadangan', 'aaaaa-aaaaa')->assertUnauthorized()->assertJsonPath('error.kode', 'kode_mfa_salah');
        $this->kirimKode($challenge, 'totp', '111111')
            ->assertForbidden()->assertJsonPath('error.kode', 'akun_terkunci')
            ->assertHeader('Retry-After');
        $this->assertTrue($pengguna->refresh()->terkunci());
        $this->assertAudit('autentikasi.mfa_gagal', $pengguna->id);
        $this->assertAudit('autentikasi.terkunci', $pengguna->id);

        // Challenge dibuang saat terkunci; kode benar pun tidak berlaku lagi.
        $this->kirimKode($challenge, 'totp', $this->kodeSekarang($rahasia))
            ->assertUnauthorized()->assertJsonPath('error.kode', 'challenge_tidak_berlaku');
    }

    public function test_challenge_kedaluwarsa_lima_menit(): void
    {
        [$pengguna, $rahasia] = $this->penggunaMfa();
        $challenge = $this->masuk($pengguna)->json('challenge_id');

        $this->travel(5)->minutes();
        $this->travel(1)->seconds();

        $this->kirimKode($challenge, 'totp', $this->kodeSekarang($rahasia))
            ->assertUnauthorized()->assertJsonPath('error.kode', 'challenge_tidak_berlaku');
    }

    public function test_challenge_ditolak_dari_sesi_lain(): void
    {
        [$pengguna, $rahasia] = $this->penggunaMfa();
        $challenge = $this->masuk($pengguna)->json('challenge_id');

        $this->mulaiSesiSpa();

        $this->kirimKode($challenge, 'totp', $this->kodeSekarang($rahasia))
            ->assertUnauthorized()->assertJsonPath('error.kode', 'challenge_tidak_berlaku');
    }

    public function test_challenge_sekali_pakai_dan_kode_totp_tidak_bisa_dipakai_ulang(): void
    {
        [$pengguna, $rahasia] = $this->penggunaMfa();
        $kode = $this->kodeSekarang($rahasia);
        $challenge = $this->masuk($pengguna)->json('challenge_id');
        $this->kirimKode($challenge, 'totp', $kode)->assertOk();

        $this->kirimKode($challenge, 'totp', $kode)
            ->assertUnauthorized()->assertJsonPath('error.kode', 'challenge_tidak_berlaku');

        // Masuk lagi di sesi lain dalam periode yang sama: kode yang sama ditolak.
        $this->mulaiSesiSpa();
        $baru = $this->masuk($pengguna)->json('challenge_id');
        $this->kirimKode($baru, 'totp', $kode)->assertUnauthorized()->assertJsonPath('error.kode', 'kode_mfa_salah');
    }

    public function test_kode_periode_sebelumnya_diterima_tetapi_dua_periode_tidak(): void
    {
        [$pengguna, $rahasia] = $this->penggunaMfa();
        $langkah = Totp::langkah(now()->getTimestamp());

        $challenge = $this->masuk($pengguna)->json('challenge_id');
        $this->kirimKode($challenge, 'totp', Totp::kode($rahasia, $langkah - 2))->assertJsonPath('error.kode', 'kode_mfa_salah');
        $this->kirimKode($challenge, 'totp', Totp::kode($rahasia, $langkah - 1))->assertOk();
    }

    public function test_kode_cadangan_masuk_sekali_pakai(): void
    {
        [$pengguna] = $this->penggunaMfa();
        $kode = $this->daftarkanKodeCadangan($pengguna);

        $challenge = $this->masuk($pengguna)->json('challenge_id');
        $this->kirimKode($challenge, 'kode_cadangan', strtoupper(str_replace('-', ' ', $kode[3])))->assertOk()
            ->assertJsonPath('status', 'masuk');
        $this->assertAudit('autentikasi.kode_cadangan_dipakai', $pengguna->id);

        $this->mulaiSesiSpa();
        $baru = $this->masuk($pengguna)->json('challenge_id');
        $this->kirimKode($baru, 'kode_cadangan', $kode[3])->assertUnauthorized()->assertJsonPath('error.kode', 'kode_mfa_salah');
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
            'status', 'pengguna' => ['id', 'nama_pengguna', 'login_terakhir_pada', 'mfa_aktif', 'pegawai', 'peran', 'masuk_sebelumnya_pada', 'masuk_sebelumnya_dari_jaringan_rs'],
            'perangkat_tepercaya',
        ])->assertJsonPath('status', 'masuk')->assertJsonPath('perangkat_tepercaya', null);
    }

    public function test_dari_luar_jaringan_rs_akun_ber_mfa_tetap_bisa_masuk_walau_tidak_ditandai(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        [$sudahDaftar, $rahasia] = $this->penggunaMfa(wajib: false);

        $challenge = $this->masuk($sudahDaftar)->assertOk()->assertJsonPath('status', 'perlu_mfa')->json('challenge_id');
        $this->kirimKode($challenge, 'totp', $this->kodeSekarang($rahasia))->assertOk()->assertJsonPath('status', 'masuk');
    }

    public function test_dari_luar_jaringan_rs_akun_tanpa_mfa_ditolak_tanpa_challenge(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $tidakDitandai = Pengguna::factory()->denganSandi(self::SANDI)->create();
        $wajib = Pengguna::factory()->denganSandi(self::SANDI)->create(['wajib_mfa' => true]);

        foreach ([$tidakDitandai, $wajib] as $pengguna) {
            $this->masuk($pengguna)->assertForbidden()
                ->assertJsonPath('error.kode', 'mfa_belum_terdaftar')
                ->assertJsonMissingPath('challenge_id');

            $this->assertDatabaseHas('audit.log_aktivitas', [
                'aksi' => 'autentikasi.gagal',
                'pengguna_id' => $pengguna->id,
                'sesudah->alasan' => 'mfa_belum_terdaftar',
            ]);
            // Sandinya benar: bukan percobaan gagal, tetapi juga tidak masuk.
            $this->assertSame(0, $pengguna->refresh()->percobaan_gagal);
            $this->assertNull($pengguna->login_terakhir_pada);
        }
        $this->minta('GET', '/api/v1/saya')->assertUnauthorized();
        $this->assertNull($this->app['session']->driver()->get('autentikasi.challenge_mfa'));
    }

    public function test_challenge_daftar_tidak_berlaku_bila_berpindah_ke_luar_jaringan_rs(): void
    {
        $pengguna = Pengguna::factory()->denganSandi(self::SANDI)->create(['wajib_mfa' => true]);
        $challenge = $this->masuk($pengguna)->assertJsonPath('status', 'perlu_daftar_mfa')->json('challenge_id');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->minta('POST', '/api/v1/autentikasi/mfa/daftar', ['challenge_id' => $challenge])
            ->assertUnauthorized()->assertJsonPath('error.kode', 'challenge_tidak_berlaku');
        $this->assertFalse($pengguna->refresh()->mfaAktif());
    }

    public function test_jaringan_rs_kosong_menolak_login_web_sebelum_sandi_diperiksa(): void
    {
        config(['autentikasi.mfa.jaringan_rs' => []]);
        [$pengguna] = $this->penggunaMfa();

        $this->masuk($pengguna)->assertStatus(503)->assertJsonPath('error.kode', 'konfigurasi_belum_lengkap');
        $this->masuk($pengguna, 'sandi-salah-sekali')->assertStatus(503);

        $this->assertSame(0, $pengguna->refresh()->percobaan_gagal);
        $this->assertDatabaseMissing('audit.log_aktivitas', ['pengguna_id' => $pengguna->id]);
    }

    public function test_akun_dinonaktifkan_di_tengah_langkah_kembali_ke_sandi(): void
    {
        [$pengguna, $rahasia] = $this->penggunaMfa();
        $challenge = $this->masuk($pengguna)->json('challenge_id');
        $pengguna->forceFill(['status' => 'nonaktif'])->save();

        $this->kirimKode($challenge, 'totp', $this->kodeSekarang($rahasia))
            ->assertUnauthorized()->assertJsonPath('error.kode', 'challenge_tidak_berlaku');
    }

    public function test_log_dan_audit_tanpa_kode_rahasia_maupun_uri_otpauth(): void
    {
        $pengguna = Pengguna::factory()->denganSandi(self::SANDI)->create(['wajib_mfa' => true]);
        $challenge = $this->masuk($pengguna)->json('challenge_id');
        $uri = $this->minta('POST', '/api/v1/autentikasi/mfa/daftar', ['challenge_id' => $challenge])->json('uri_otpauth');
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $this->minta('POST', '/api/v1/autentikasi/mfa/daftar/konfirmasi', ['challenge_id' => $challenge, 'kode' => '000000']);
        $kodeBenar = $this->kodeSekarang($query['secret']);
        $kodeCadangan = $this->minta('POST', '/api/v1/autentikasi/mfa/daftar/konfirmasi', ['challenge_id' => $challenge, 'kode' => $kodeBenar])
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

    private function kirimKode(string $challenge, string $metode, string $kode): TestResponse
    {
        return $this->minta('POST', '/api/v1/autentikasi/mfa', ['challenge_id' => $challenge, 'metode' => $metode, 'kode' => $kode]);
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
