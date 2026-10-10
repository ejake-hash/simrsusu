<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Application\PendaftaranPerangkat;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Support\CsrfTetapDiperiksa;
use Tests\TestCase;

/**
 * Alur SPA apa adanya: cookie dibawa antar-permintaan, CSRF tetap diperiksa, dan guard serta
 * isi sesi di memori dibuang sebelum tiap permintaan (seperti permintaan HTTP baru).
 */
class AutentikasiTest extends TestCase
{
    private const SANDI = 'kuda-lumping-biru-29';

    private const ORIGIN = 'http://localhost:5173';

    /** @var array<string, string> cookie mentah (terenkripsi) seperti di peramban */
    private array $kue = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.middleware.validate_csrf_token' => CsrfTetapDiperiksa::class]);
        $this->minta('GET', '/sanctum/csrf-cookie')->assertNoContent();
    }

    public function test_masuk_berhasil_membuat_sesi_dan_mengembalikan_profil(): void
    {
        $pengguna = $this->buatPengguna();

        $respons = $this->masuk($pengguna->nama_pengguna, self::SANDI);

        $respons->assertOk()
            ->assertJsonPath('data.id', $pengguna->id)
            ->assertJsonPath('data.nama_pengguna', $pengguna->nama_pengguna)
            ->assertJsonPath('data.pegawai.id', $pengguna->pegawai_id)
            ->assertJsonMissingPath('data.sandi_hash');

        $kueSesi = $respons->getCookie(config('session.cookie'), false);
        $this->assertTrue($kueSesi->isHttpOnly());
        $this->assertSame('strict', $kueSesi->getSameSite());
        $this->assertTrue($kueSesi->isSecure(), 'Di luar APP_ENV=local cookie sesi harus Secure.');

        $this->minta('GET', '/api/v1/saya')->assertOk()->assertJsonPath('data.id', $pengguna->id);
        $this->assertAudit('autentikasi.masuk', $pengguna->id);
    }

    public function test_sandi_salah_dan_akun_tidak_ada_mendapat_galat_yang_sama(): void
    {
        $pengguna = $this->buatPengguna();

        $sandiSalah = $this->masuk($pengguna->nama_pengguna, 'bukan-sandi-yang-benar');
        $tidakAda = $this->masuk('tidak.ada.akunnya', self::SANDI);

        $sandiSalah->assertUnauthorized()->assertExactJson([
            'galat' => ['kode' => 'kredensial_salah', 'pesan' => 'Nama pengguna atau sandi salah.', 'rincian' => []],
        ]);
        $this->assertSame($sandiSalah->status(), $tidakAda->status());
        $this->assertSame($sandiSalah->json(), $tidakAda->json());
        $this->assertSame(1, $pengguna->refresh()->percobaan_gagal);
        $this->assertAudit('autentikasi.gagal', $pengguna->id);
    }

    public function test_hash_tetap_diverifikasi_saat_akun_tidak_ada(): void
    {
        $pengguna = $this->buatPengguna();
        $hashDiperiksa = [];
        // MasukPengguna menerima kontrak Hasher = binding 'hash.driver' (bukan facade Hash).
        $hasher = Mockery::mock($this->app->make('hash.driver'))->makePartial();
        $hasher->shouldReceive('check')->twice()
            ->withArgs(function (string $sandi, ?string $hash) use (&$hashDiperiksa) {
                $hashDiperiksa[] = $hash;

                return true;
            })
            ->passthru();
        $this->app->instance('hash.driver', $hasher);

        $sandiSalah = $this->masuk($pengguna->nama_pengguna, 'bukan-sandi-yang-benar');
        $tidakAda = $this->masuk('tidak.ada.akunnya', 'bukan-sandi-yang-benar');

        // Dua-duanya memverifikasi hash Argon2id sungguhan (akun asli vs hash tiruan), jadi kerjanya setara.
        $this->assertCount(2, $hashDiperiksa);
        $this->assertSame($pengguna->sandi_hash, $hashDiperiksa[0]);
        $this->assertStringStartsWith('$argon2id$', $hashDiperiksa[1]);
        $this->assertNotSame($hashDiperiksa[0], $hashDiperiksa[1]);

        $this->assertSame($sandiSalah->status(), $tidakAda->status());
        $this->assertSame($sandiSalah->getContent(), $tidakAda->getContent());
        $this->assertSame($this->headerTetap($sandiSalah), $this->headerTetap($tidakAda));
    }

    public function test_lima_kali_gagal_mengunci_akun_lima_belas_menit(): void
    {
        $pengguna = $this->buatPengguna();

        foreach (range(1, 5) as $_) {
            $this->masuk($pengguna->nama_pengguna, 'sandi-salah-berulang')->assertUnauthorized();
        }

        // Sandi benar pun ditolak selama terkunci, dan penguncian tersimpan di DB.
        $this->masuk($pengguna->nama_pengguna, self::SANDI)
            ->assertForbidden()->assertJsonPath('galat.kode', 'akun_terkunci');
        $this->assertTrue($pengguna->refresh()->terkunci());
        $this->assertEqualsWithDelta(15 * 60, now()->diffInSeconds($pengguna->terkunci_sampai), 5);
        $this->assertAudit('autentikasi.terkunci', $pengguna->id);

        $this->travel(16)->minutes();
        $this->masuk($pengguna->nama_pengguna, self::SANDI)->assertOk();
        $this->assertSame(0, $pengguna->refresh()->percobaan_gagal);
    }

    public function test_status_terkunci_tidak_membocorkan_apakah_akun_ada(): void
    {
        foreach (range(1, 5) as $_) {
            $this->masuk('akun.fiktif.sama.sekali', 'sandi-salah-berulang')->assertUnauthorized();
        }

        $this->masuk('akun.fiktif.sama.sekali', 'apa-saja-panjang')
            ->assertForbidden()->assertJsonPath('galat.kode', 'akun_terkunci');
    }

    public function test_akun_nonaktif_ditolak_hanya_bila_sandi_benar(): void
    {
        $pengguna = $this->buatPengguna(nonaktif: true);

        $this->masuk($pengguna->nama_pengguna, 'bukan-sandi-yang-benar')
            ->assertUnauthorized()->assertJsonPath('galat.kode', 'kredensial_salah');
        $this->masuk($pengguna->nama_pengguna, self::SANDI)
            ->assertForbidden()->assertJsonPath('galat.kode', 'akun_nonaktif');
        $this->minta('GET', '/api/v1/saya')->assertUnauthorized();
    }

    public function test_keluar_mengakhiri_sesi(): void
    {
        $pengguna = $this->buatPengguna();
        $this->masuk($pengguna->nama_pengguna, self::SANDI)->assertOk();

        $this->minta('POST', '/api/v1/autentikasi/keluar')->assertNoContent();

        $this->minta('GET', '/api/v1/saya')->assertUnauthorized()->assertJsonPath('galat.kode', 'belum_masuk');
        $this->assertAudit('autentikasi.keluar', $pengguna->id);
    }

    public function test_saya_tanpa_sesi_401_dengan_format_galat(): void
    {
        $this->minta('GET', '/api/v1/saya')->assertUnauthorized()->assertExactJson([
            'galat' => ['kode' => 'belum_masuk', 'pesan' => 'Silakan masuk terlebih dahulu.', 'rincian' => []],
        ]);
    }

    public function test_csrf_wajib_untuk_masuk(): void
    {
        $pengguna = $this->buatPengguna();
        unset($this->kue['XSRF-TOKEN']);

        $this->masuk($pengguna->nama_pengguna, self::SANDI)
            ->assertForbidden()->assertJsonPath('galat.kode', 'csrf_tidak_valid');
        $this->assertNull($pengguna->refresh()->login_terakhir_pada);
    }

    public function test_masuk_dari_luar_domain_spa_ditolak_sebelum_sandi_diperiksa(): void
    {
        $pengguna = $this->buatPengguna();
        $this->defaultHeaders = [];
        $this->unencryptedCookies = [];

        $this->postJson('/api/v1/autentikasi/masuk', ['nama_pengguna' => $pengguna->nama_pengguna, 'sandi' => self::SANDI])
            ->assertForbidden()->assertJsonPath('galat.kode', 'asal_tidak_diizinkan');
        $this->assertNull($pengguna->refresh()->login_terakhir_pada);
    }

    public function test_sesi_dicabut_saat_akun_dinonaktifkan(): void
    {
        $pengguna = $this->buatPengguna();
        $this->masuk($pengguna->nama_pengguna, self::SANDI)->assertOk();

        $pengguna->forceFill(['status' => 'nonaktif'])->save();

        $this->minta('GET', '/api/v1/saya')->assertUnauthorized()->assertJsonPath('galat.kode', 'sesi_dicabut');
        $this->minta('GET', '/api/v1/saya')->assertUnauthorized()->assertJsonPath('galat.kode', 'belum_masuk');
    }

    public function test_sesi_berakhir_setelah_diam_lima_belas_menit(): void
    {
        $pengguna = $this->buatPengguna();
        $this->masuk($pengguna->nama_pengguna, self::SANDI)->assertOk();

        $this->travel(14)->minutes();
        $this->minta('GET', '/api/v1/saya')->assertOk();

        $this->travel(16)->minutes();
        $this->minta('GET', '/api/v1/saya')->assertUnauthorized()->assertJsonPath('galat.kode', 'sesi_kedaluwarsa');
    }

    public function test_perangkat_terdaftar_mendapat_batas_diam_enam_puluh_menit(): void
    {
        $this->app->instance(PendaftaranPerangkat::class, new class implements PendaftaranPerangkat
        {
            public function terdaftar(Request $request): bool
            {
                return true;
            }
        });
        $pengguna = $this->buatPengguna();
        $this->masuk($pengguna->nama_pengguna, self::SANDI)->assertOk();

        $this->travel(59)->minutes();
        $this->minta('GET', '/api/v1/saya')->assertOk();

        $this->travel(61)->minutes();
        $this->minta('GET', '/api/v1/saya')->assertUnauthorized()->assertJsonPath('galat.kode', 'sesi_kedaluwarsa');
    }

    public function test_batas_laju_per_nama_pengguna(): void
    {
        config(['autentikasi.batas_laju.per_nama_pengguna_per_menit' => 2]);

        $this->masuk('target.batas', 'sandi-salah-satu')->assertUnauthorized();
        $this->masuk('target.batas', 'sandi-salah-dua')->assertUnauthorized();
        $this->masuk('target.batas', 'sandi-salah-tiga')
            ->assertTooManyRequests()->assertJsonPath('galat.kode', 'terlalu_banyak_permintaan')
            ->assertHeader('Retry-After');

        $this->masuk('nama.lain', 'sandi-salah-satu')->assertUnauthorized();
    }

    public function test_validasi_memakai_format_galat(): void
    {
        $this->minta('POST', '/api/v1/autentikasi/masuk', [])
            ->assertUnprocessable()
            ->assertJsonPath('galat.kode', 'validasi_gagal')
            ->assertJsonPath('galat.rincian.0.kolom', 'nama_pengguna');
    }

    public function test_log_tidak_memuat_sandi(): void
    {
        $pengguna = $this->buatPengguna();
        $this->masuk($pengguna->nama_pengguna, 'sandi-rahasia-yang-salah')->assertUnauthorized();
        $this->masuk($pengguna->nama_pengguna, self::SANDI)->assertOk();

        $log = DB::table('audit.log_aktivitas')->get()->toJson();
        $this->assertStringNotContainsString('sandi-rahasia-yang-salah', $log);
        $this->assertStringNotContainsString(self::SANDI, $log);
        $this->assertStringNotContainsString('$argon2id$', $log);
    }

    public function test_simrs_app_tidak_bisa_menghapus_pengguna(): void
    {
        $pengguna = $this->buatPengguna();

        $this->assertDitolak(fn () => DB::table('akses.pengguna')->where('id', $pengguna->id)->delete());
    }

    private function buatPengguna(bool $nonaktif = false): Pengguna
    {
        $pabrik = Pengguna::factory()->denganSandi(self::SANDI);

        return ($nonaktif ? $pabrik->nonaktif() : $pabrik)->create();
    }

    private function masuk(string $namaPengguna, string $sandi): TestResponse
    {
        return $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $namaPengguna, 'sandi' => $sandi]);
    }

    private function minta(string $metode, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();

        $this->unencryptedCookies = $this->kue;
        $this->defaultHeaders = array_filter([
            'Origin' => self::ORIGIN,
            'Referer' => self::ORIGIN.'/',
            'X-XSRF-TOKEN' => $this->kue['XSRF-TOKEN'] ?? null,
        ]);

        $respons = $this->withCredentials()->json($metode, $uri, $data);

        foreach ($respons->headers->getCookies() as $kue) {
            if ($kue->isCleared()) {
                unset($this->kue[$kue->getName()]);
            } else {
                $this->kue[$kue->getName()] = $kue->getValue();
            }
        }

        return $respons;
    }

    /** Nama header respons tanpa yang pasti berubah per permintaan (tanggal, cookie, sisa kuota laju). */
    private function headerTetap(TestResponse $respons): array
    {
        $nama = array_keys($respons->headers->all());
        $nama = array_values(array_diff($nama, ['date', 'set-cookie', 'x-ratelimit-remaining']));
        sort($nama);

        return $nama;
    }

    private function assertAudit(string $aksi, string $penggunaId): void
    {
        $this->assertDatabaseHas('audit.log_aktivitas', ['aksi' => $aksi, 'pengguna_id' => $penggunaId]);
    }
}
