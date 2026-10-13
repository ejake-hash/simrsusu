<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Application\ResetSandi;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.14: reset sandi oleh admin dan perintah konsol darurat. */
class ResetSandiTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'BA verifikasi identitas 12/2026';

    private string $berkasLog;

    protected function setUp(): void
    {
        parent::setUp();
        // Semua log aplikasi ke satu berkas sementara: sandi sementara tidak boleh muncul di sana.
        $this->berkasLog = sys_get_temp_dir().'/reset-sandi-'.bin2hex(random_bytes(6)).'.log';
        config(['logging.default' => 'uji_reset_sandi', 'logging.channels.uji_reset_sandi' => ['driver' => 'single', 'path' => $this->berkasLog, 'level' => 'debug']]);
        Log::info('mulai uji reset sandi');
    }

    protected function tearDown(): void
    {
        @unlink($this->berkasLog);
        parent::tearDown();
    }

    private function assertTanpaSandiDiLogDanAudit(string $sandi): void
    {
        $this->assertFileExists($this->berkasLog);
        $this->assertStringNotContainsString($sandi, (string) file_get_contents($this->berkasLog));
        // Dicari di PHP, bukan LIKE: sandi bisa memuat \\, % atau _.
        foreach (DB::table('audit.log_aktivitas')->get(['sebelum', 'sesudah']) as $baris) {
            $this->assertStringNotContainsString(json_encode($sandi), (string) $baris->sesudah.$baris->sebelum);
            $this->assertStringNotContainsString($sandi, (string) $baris->sesudah.$baris->sebelum);
        }
    }

    public function test_reset_memutus_sesi_lalu_masuk_dengan_sandi_sementara_wajib_ganti(): void
    {
        $akun = $this->buatPengguna();
        $this->mulaiSesiSpa();
        $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $akun->nama_pengguna, 'sandi' => self::SANDI_UJI])->assertOk();
        $perambanAkun = $this->peramban();
        // Sesudah itu terkunci karena sandi salah: reset membuka kuncinya.
        $akun->forceFill(['percobaan_gagal' => 5, 'terkunci_sampai' => now()->addMinutes(10)])->save();

        $pengelola = $this->pengelola();
        $this->masukSebagai($pengelola);
        $reset = $this->minta('POST', "/api/v1/akun/{$akun->id}/sandi/reset", ['alasan' => 'Lupa sandi, datang ke unit SIMRS', 'dasar' => self::DASAR])
            ->assertOk();
        $this->assertSesuaiKontrak($reset, 'POST', '/akun/{id}/sandi/reset');
        $this->assertStringContainsString('no-store', (string) $reset->headers->get('Cache-Control'));
        $reset->assertJsonPath('data.akun.id', $akun->id)->assertJsonPath('data.akun.terkunci_sampai', null);
        $this->assertNotNull($akun->refresh()->sesi_dicabut_pada, 'Sesi diputus lewat sesi_dicabut_pada, bukan hanya karena hash berubah.');
        $sandi = (string) $reset->json('data.sandi_sementara');
        $this->assertGreaterThanOrEqual(16, strlen($sandi));

        $audit = $this->assertAuditTerakhir(ResetSandi::AUDIT, $akun->id);
        $this->assertSame($pengelola->id, $audit->pengguna_id);
        $this->assertEquals(['alasan' => 'Lupa sandi, datang ke unit SIMRS', 'dasar' => self::DASAR, 'sesi_diputus' => true], json_decode($audit->sesudah, true));

        // Sesi lama akun itu putus. Hash sandi berubah, jadi AuthenticateSession sudah mengakhirinya (belum_masuk) sebelum
        // pemeriksaan sesi_dicabut_pada; keduanya 401.
        $this->pakaiPeramban($perambanAkun);
        $this->minta('GET', '/api/v1/saya')->assertUnauthorized();

        // Sandi lama tidak berlaku; sandi sementara masuk lalu dipaksa ganti.
        $this->mulaiSesiSpa();
        $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $akun->nama_pengguna, 'sandi' => self::SANDI_UJI])->assertUnauthorized();
        $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $akun->nama_pengguna, 'sandi' => $sandi])
            ->assertOk()->assertJsonPath('status', 'masuk');
        $this->minta('GET', '/api/v1/saya')->assertOk()->assertJsonPath('data.wajib_ganti_sandi', true);
        $this->minta('GET', '/api/v1/menu')->assertForbidden()->assertJsonPath('error.kode', 'wajib_ganti_sandi');
        $this->minta('POST', '/api/v1/saya/sandi', ['sandi_lama' => $sandi, 'sandi_baru' => 'Kuda-Biru-Lompat-77'])
            ->assertOk()->assertJsonPath('data.wajib_ganti_sandi', false);

        $this->assertTanpaSandiDiLogDanAudit($sandi);
    }

    public function test_sendiri_superadmin_dan_akun_nonaktif_ditolak(): void
    {
        [$superadmin] = $this->superadminBerMfa();
        $pengelola = $this->pengelola();
        $nonaktif = $this->buatPengguna(['status' => 'nonaktif']);
        $isian = ['alasan' => 'Lupa sandi', 'dasar' => self::DASAR];

        $this->masukSebagai($pengelola);
        foreach ([[$pengelola->id, 403, 'diri_sendiri'], [$superadmin->id, 403, 'akun_superadmin'], [$nonaktif->id, 409, 'akun_nonaktif']] as [$id, $status, $kode]) {
            $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/akun/{$id}/sandi/reset", $isian)->assertStatus($status)->assertJsonPath('error.kode', $kode),
                'POST', '/akun/{id}/sandi/reset');
        }
        $this->minta('POST', "/api/v1/akun/{$nonaktif->id}/sandi/reset", ['alasan' => '', 'dasar' => ''])->assertStatus(422);

        // Superadmin mereset dirinya → 403; superadmin lain boleh.
        $this->masukSebagai($superadmin);
        $this->minta('POST', "/api/v1/akun/{$superadmin->id}/sandi/reset", $isian)->assertForbidden()->assertJsonPath('error.kode', 'diri_sendiri');
        [$superadminLain] = $this->superadminBerMfa();
        $this->minta('POST', "/api/v1/akun/{$superadminLain->id}/sandi/reset", $isian)->assertOk();
        $this->assertTrue($superadminLain->refresh()->wajib_ganti_sandi);
    }

    public function test_tanpa_izin_ditolak(): void
    {
        $akun = $this->buatPengguna();
        $this->masukSebagai($this->buatPengguna());

        $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/akun/{$akun->id}/sandi/reset", ['alasan' => 'Lupa sandi', 'dasar' => self::DASAR])->assertForbidden(),
            'POST', '/akun/{id}/sandi/reset');
        $this->assertFalse($akun->refresh()->wajib_ganti_sandi);
    }

    public function test_konsol_darurat_mencetak_sandi_sekali_dan_tercatat_audit(): void
    {
        [$superadmin] = $this->superadminBerMfa();
        $sudoAsli = getenv('SUDO_USER');
        putenv('SUDO_USER=petugas.server');
        $this->beforeApplicationDestroyed(fn () => putenv($sudoAsli === false ? 'SUDO_USER' : 'SUDO_USER='.$sudoAsli));
        config(['autentikasi.sandi.panjang_minimal' => 30]);

        $this->artisan('akses:reset-sandi', ['nama_pengguna' => strtoupper($superadmin->nama_pengguna), '--alasan' => 'Tidak ada superadmin lain', '--dasar' => self::DASAR])
            ->expectsConfirmation("Reset sandi {$superadmin->nama_pengguna}? Semua sesinya diputus dan sandi lama tidak berlaku.", 'yes')
            ->expectsOutputToContain('Sandi sementara (hanya ditampilkan sekali')
            ->assertSuccessful();

        $superadmin->refresh();
        $this->assertTrue($superadmin->wajib_ganti_sandi);
        $audit = $this->assertAuditTerakhir(ResetSandi::AUDIT_KONSOL, $superadmin->id);
        $this->assertNull($audit->pengguna_id);
        $catatan = json_decode($audit->sesudah, true);
        $this->assertEqualsCanonicalizing(['alasan', 'dasar', 'pengguna_os', 'pengguna_efektif', 'sesi_diputus'], array_keys($catatan));
        $this->assertSame('petugas.server', $catatan['pengguna_os'], 'Lewat sudo: yang tercatat SUDO_USER.');
        $this->assertNotSame('', $catatan['pengguna_efektif']);
        $this->assertStringNotContainsString('sandi_sementara', (string) $audit->sesudah);

        // Tanpa konfirmasi (--force): sandi yang dicetak bisa dipakai masuk; tidak ada di log maupun audit.
        Artisan::call('akses:reset-sandi', ['nama_pengguna' => $superadmin->nama_pengguna, '--alasan' => 'Tidak ada superadmin lain', '--dasar' => self::DASAR, '--force' => true]);
        $this->assertSame(1, preg_match('/hanya ditampilkan sekali[^:]*: (\S+)/', Artisan::output(), $cocok));
        $sandi = $cocok[1];
        $this->assertGreaterThanOrEqual(30, mb_strlen($sandi), 'Tidak lebih pendek dari panjang minimal instalasi.');
        $this->mulaiSesiSpa();
        $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $superadmin->nama_pengguna, 'sandi' => $sandi])
            ->assertOk()->assertJsonPath('status', 'perlu_mfa');
        $this->assertTanpaSandiDiLogDanAudit($sandi);
    }

    public function test_konsol_menolak_akun_tidak_ada_isian_kurang_atau_dibatalkan(): void
    {
        $akun = $this->buatPengguna();
        $hash = $akun->sandi_hash;

        $this->artisan('akses:reset-sandi', ['nama_pengguna' => 'tidak.ada', '--alasan' => 'Lupa sandi', '--dasar' => self::DASAR])
            ->expectsOutput('Akun tidak ditemukan.')->assertFailed();
        $this->artisan('akses:reset-sandi', ['nama_pengguna' => $akun->nama_pengguna, '--alasan' => 'x', '--dasar' => self::DASAR])->assertFailed();
        $this->artisan('akses:reset-sandi', ['nama_pengguna' => $akun->nama_pengguna, '--alasan' => 'Lupa sandi', '--dasar' => self::DASAR])
            ->expectsConfirmation("Reset sandi {$akun->nama_pengguna}? Semua sesinya diputus dan sandi lama tidak berlaku.", 'no')
            ->assertFailed();

        // Masih ada superadmin aktif lain: konsol ditolak, reset lewat layar.
        [$superadminA] = $this->superadminBerMfa();
        [$superadminB] = $this->superadminBerMfa();
        $this->artisan('akses:reset-sandi', ['nama_pengguna' => $superadminA->nama_pengguna, '--alasan' => 'Lupa sandi', '--dasar' => self::DASAR, '--force' => true])
            ->expectsOutputToContain('Masih ada superadmin aktif lain')->assertFailed();
        $this->artisan('akses:reset-sandi', ['nama_pengguna' => $akun->nama_pengguna, '--alasan' => 'Lupa sandi', '--dasar' => self::DASAR, '--force' => true])
            ->assertFailed();
        $this->assertFalse($superadminB->refresh()->wajib_ganti_sandi);

        $this->assertSame($hash, $akun->refresh()->sandi_hash);
        $this->assertFalse(DB::table('audit.log_aktivitas')->where('aksi', ResetSandi::AUDIT_KONSOL)->where('subjek_id', $akun->id)->exists());
    }
}
