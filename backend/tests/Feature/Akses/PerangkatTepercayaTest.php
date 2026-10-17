<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Application\KelolaPerangkat;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PerangkatTepercaya;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.13: perangkat tepercaya (MFA sekali per perangkat per masa percaya). */
class PerangkatTepercayaTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const DASAR = 'SK Direktur 1.13/2026';

    private const LUAR = '203.0.113.10';

    private function cookie(): string
    {
        return (string) config('autentikasi.perangkat_tepercaya.cookie');
    }

    /** Sandi lalu kode TOTP; $percayai = centang "Percayai perangkat ini". */
    private function masukDenganMfa(Pengguna $pengguna, string $rahasia, bool $percayai = false): TestResponse
    {
        // Masuk berulang dalam satu periode TOTP; model bisa basi, jadi langsung ke DB.
        Pengguna::query()->whereKey($pengguna->id)->update(['mfa_langkah_terakhir' => null]);
        $sandi = $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $pengguna->nama_pengguna, 'sandi' => self::SANDI_UJI])
            ->assertOk()->assertJsonPath('status', 'perlu_mfa');

        return $this->minta('POST', '/api/v1/autentikasi/mfa', [
            'challenge_id' => $sandi->json('challenge_id'), 'metode' => 'totp',
            'kode' => Totp::kode($rahasia, Totp::langkah(now()->getTimestamp())), 'percayai_perangkat' => $percayai,
        ])->assertOk()->assertJsonPath('status', 'masuk');
    }

    private function langkahSandi(Pengguna $pengguna): TestResponse
    {
        return $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $pengguna->nama_pengguna, 'sandi' => self::SANDI_UJI])->assertOk();
    }

    /** Sesudah waktu dimajukan: SPA mengambil cookie CSRF baru, cookie perangkat tetap di peramban. */
    private function pakaiPerambanLama(array $kue): void
    {
        $this->pakaiPeramban($kue);
        $this->minta('GET', '/sanctum/csrf-cookie')->assertNoContent();
    }

    private function keluar(): void
    {
        $this->minta('POST', '/api/v1/autentikasi/keluar')->assertNoContent();
    }

    /** @return array{0: Pengguna, 1: string} akun biasa ber-MFA (wajib MFA per akun), tanpa peran */
    private function akunBerMfa(): array
    {
        $rahasia = Totp::rahasiaBaru();
        $akun = $this->buatPengguna(['wajib_mfa' => true]);
        $akun->forceFill(['mfa_rahasia_enkripsi' => $rahasia, 'mfa_aktif_pada' => now()])->save();

        return [$akun, $rahasia];
    }

    public function test_superadmin_percayai_lalu_masuk_tanpa_kode_di_jaringan_rs_dan_diminta_kode_dari_luar(): void
    {
        [$superadmin, $rahasia] = $this->superadminBerMfa();
        $this->mulaiSesiSpa();

        $tawaran = $this->langkahSandi($superadmin)->assertJsonPath('status', 'perlu_mfa');
        $tawaran->assertJsonPath('percayai_perangkat', ['tersedia' => true, 'hari' => 7]);
        $masuk = $this->masukDenganMfa($superadmin, $rahasia, percayai: true);

        // Cookie token: HttpOnly, SameSite=Strict, path sempit, terpisah dari sesi; nilainya tidak di badan respons.
        $kue = collect($masuk->headers->getCookies())->first(fn ($k) => $k->getName() === $this->cookie());
        $this->assertNotNull($kue);
        $this->assertTrue($kue->isHttpOnly());
        $this->assertSame('strict', $kue->getSameSite());
        $this->assertSame('/api/v1/autentikasi', $kue->getPath());
        $perangkat = PerangkatTepercaya::query()->where('pengguna_id', $superadmin->id)->sole();
        $masuk->assertJsonPath('perangkat_tepercaya.id', $perangkat->id);
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $perangkat->berlaku_sampai->getTimestamp(), 5);
        $token = decrypt($this->peramban()[$this->cookie()], false);
        $this->assertStringNotContainsString(explode('|', $token)[1] ?? $token, (string) $masuk->getContent());
        $audit = $this->assertAuditTerakhir('akses.perangkat_dipercaya', $superadmin->id);
        $this->assertStringNotContainsString(explode('|', $token)[1] ?? $token, (string) $audit->sesudah);
        $this->assertSame(hash('sha256', explode('|', $token)[1] ?? $token), $perangkat->token_hash);

        // Keluar (cookie tetap), masuk lagi dari jaringan RS: tanpa kode.
        $this->keluar();
        $this->assertArrayHasKey($this->cookie(), $this->peramban());
        $lagi = $this->langkahSandi($superadmin)->assertJsonPath('status', 'masuk')->assertJsonPath('perangkat_tepercaya.id', $perangkat->id);
        $this->assertNotNull($lagi->json('perangkat_tepercaya.berlaku_sampai'));
        $this->assertEquals(['mfa_dilewati' => 'perangkat_tepercaya', 'perangkat_id' => $perangkat->id],
            json_decode($this->assertAuditTerakhir('autentikasi.masuk', $superadmin->id)->sesudah, true));
        $this->assertNotNull($perangkat->refresh()->terakhir_dipakai_pada);

        // MFA ulang (K11) tidak dilewati: izin butuh_mfa minta kode.
        $this->minta('GET', '/api/v1/kebijakan-keamanan')->assertOk();
        $this->minta('PUT', '/api/v1/kebijakan-keamanan', ['perangkat_tepercaya_hari' => 20, 'dasar_perubahan' => self::DASAR])
            ->assertForbidden()->assertJsonPath('error.kode', 'mfa_ulang_diperlukan');

        // Dari luar jaringan RS: diminta kode, tanpa tawaran percayai.
        $this->keluar();
        $this->withServerVariables(['REMOTE_ADDR' => self::LUAR]);
        $this->langkahSandi($superadmin)->assertJsonPath('status', 'perlu_mfa')
            ->assertJsonPath('percayai_perangkat', ['tersedia' => false, 'hari' => 0]);
    }

    public function test_masa_percaya_superadmin_tujuh_hari_akun_biasa_tiga_puluh(): void
    {
        [$superadmin, $rahasiaSuperadmin] = $this->superadminBerMfa();
        [$akun, $rahasia] = $this->akunBerMfa();

        $this->mulaiSesiSpa();
        $this->masukDenganMfa($superadmin, $rahasiaSuperadmin, percayai: true);
        $perambanSuperadmin = $this->peramban();
        $this->mulaiSesiSpa();
        $this->langkahSandi($akun)->assertJsonPath('percayai_perangkat', ['tersedia' => true, 'hari' => 30]);
        $this->masukDenganMfa($akun, $rahasia, percayai: true);
        $perambanAkun = $this->peramban();

        $this->travel(6)->days();
        $this->pakaiPerambanLama($perambanSuperadmin);
        $this->langkahSandi($superadmin)->assertJsonPath('status', 'masuk');
        $this->travel(2)->days();
        $this->pakaiPerambanLama($perambanSuperadmin);
        $this->langkahSandi($superadmin)->assertJsonPath('status', 'perlu_mfa');

        $this->travel(21)->days();
        $this->pakaiPerambanLama($perambanAkun);
        $this->langkahSandi($akun)->assertJsonPath('status', 'masuk');
        $this->travel(2)->days();
        $this->pakaiPerambanLama($perambanAkun);
        $this->langkahSandi($akun)->assertJsonPath('status', 'perlu_mfa');
    }

    public function test_token_terikat_akun_dan_dicabut_saat_ganti_sandi_atau_reset_mfa(): void
    {
        [$akun, $rahasia] = $this->akunBerMfa();
        [$lain, $rahasiaLain] = $this->akunBerMfa();
        $this->mulaiSesiSpa();
        $this->masukDenganMfa($akun, $rahasia, percayai: true);
        $kueCuri = $this->peramban()[$this->cookie()];

        // Token dicuri dipakai akun lain → tetap diminta kode.
        $this->mulaiSesiSpa();
        $this->pakaiPeramban([...$this->peramban(), $this->cookie() => $kueCuri]);
        $this->langkahSandi($lain)->assertJsonPath('status', 'perlu_mfa');

        // Ganti sandi mencabut semua perangkat akun itu.
        $this->mulaiSesiSpa();
        $this->masukDenganMfa($akun, $rahasia, percayai: true);
        $this->assertSame(2, PerangkatTepercaya::query()->where('pengguna_id', $akun->id)->whereNull('dicabut_pada')->count());
        $this->minta('POST', '/api/v1/saya/sandi', ['sandi_lama' => self::SANDI_UJI, 'sandi_baru' => 'Kuda-Biru-Lompat-77'])->assertOk();
        $this->assertSame(0, PerangkatTepercaya::query()->where('pengguna_id', $akun->id)->whereNull('dicabut_pada')->count());
        $this->assertSame(['sandi_diganti'], PerangkatTepercaya::query()->where('pengguna_id', $akun->id)->distinct()->pluck('alasan_cabut')->all());
        $this->assertSame('sandi_diganti', json_decode($this->assertAuditTerakhir('akses.perangkat_dicabut', $akun->id)->sesudah, true)['alasan']);

        // Reset MFA oleh admin mencabut perangkat akun lain itu.
        $this->mulaiSesiSpa();
        $this->masukDenganMfa($lain, $rahasiaLain, percayai: true);
        $this->masukSebagai($this->pengelola());
        $this->minta('POST', "/api/v1/akun/{$lain->id}/mfa/reset", ['alasan' => 'Ponsel hilang', 'dasar' => self::DASAR])->assertOk();
        $this->assertSame('mfa_direset', PerangkatTepercaya::query()->where('pengguna_id', $lain->id)->sole()->alasan_cabut);

        // Reset sandi oleh admin (tiket 1.14) juga mencabut perangkat akun itu.
        [$empat, $rahasiaEmpat] = $this->akunBerMfa();
        $this->mulaiSesiSpa();
        $this->masukDenganMfa($empat, $rahasiaEmpat, percayai: true);
        $this->masukSebagai($this->pengelola());
        $this->minta('POST', "/api/v1/akun/{$empat->id}/sandi/reset", ['alasan' => 'Lupa sandi', 'dasar' => self::DASAR])->assertOk();
        $this->assertSame('sandi_direset', PerangkatTepercaya::query()->where('pengguna_id', $empat->id)->sole()->alasan_cabut);

        // Nonaktif juga.
        [$ketiga, $rahasiaKetiga] = $this->akunBerMfa();
        $this->mulaiSesiSpa();
        $this->masukDenganMfa($ketiga, $rahasiaKetiga, percayai: true);
        $this->masukSebagai($this->pengelola());
        $this->minta('POST', "/api/v1/akun/{$ketiga->id}/nonaktifkan", ['alasan' => 'Pindah tugas'])->assertOk();
        $this->assertSame('akun_nonaktif', PerangkatTepercaya::query()->where('pengguna_id', $ketiga->id)->sole()->alasan_cabut);
    }

    public function test_batas_diam_enam_puluh_menit_hanya_di_perangkat_tepercaya(): void
    {
        [$akun, $rahasia] = $this->akunBerMfa();
        $this->mulaiSesiSpa();
        $this->masukDenganMfa($akun, $rahasia, percayai: true);
        $tepercaya = $this->peramban();
        $this->mulaiSesiSpa();
        $this->masukDenganMfa($akun, $rahasia);
        $biasa = $this->peramban();

        $this->travel(30)->minutes();
        $this->pakaiPeramban($biasa);
        $this->minta('GET', '/api/v1/saya')->assertUnauthorized()->assertJsonPath('error.kode', 'sesi_kedaluwarsa');
        $this->pakaiPeramban($tepercaya);
        $this->minta('GET', '/api/v1/saya')->assertOk();
    }

    public function test_kebijakan_peran_dan_instalasi_membatasi_dan_memendekkan_token(): void
    {
        [$akun, $rahasia] = $this->akunBerMfa();
        $peran = $this->buatPeran(['igd.papan']);
        $this->tugaskan($akun, $peran);
        $peran->forceFill(['masa_percaya_hari' => 0])->save();
        $this->mulaiSesiSpa();
        $this->langkahSandi($akun)->assertJsonPath('percayai_perangkat', ['tersedia' => false, 'hari' => 0]);
        // Centang diabaikan bila tidak tersedia.
        $this->masukDenganMfa($akun, $rahasia, percayai: true)->assertJsonPath('perangkat_tepercaya', null);
        $this->assertSame(0, PerangkatTepercaya::query()->where('pengguna_id', $akun->id)->count());

        $peran->forceFill(['masa_percaya_hari' => 10])->save();
        $this->mulaiSesiSpa();
        $this->masukDenganMfa($akun, $rahasia, percayai: true);
        $peramban = $this->peramban();

        // Kebijakan instalasi turun ke 3 hari: token yang sudah ada ikut memendek.
        DB::table('akses.kebijakan_keamanan')->update(['perangkat_tepercaya_hari' => 3]);
        $this->travel(4)->days();
        $this->pakaiPerambanLama($peramban);
        $this->langkahSandi($akun)->assertJsonPath('status', 'perlu_mfa');
    }

    public function test_daftar_dan_cabut_perangkat_sendiri_serta_oleh_admin(): void
    {
        [$akun, $rahasia] = $this->akunBerMfa();
        $this->mulaiSesiSpa();
        $this->masukDenganMfa($akun, $rahasia, percayai: true);
        $lain = PerangkatTepercaya::query()->where('pengguna_id', $akun->id)->sole();
        $this->mulaiSesiSpa();
        $this->headerTambahan = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36'];
        $this->masukDenganMfa($akun, $rahasia, percayai: true);
        $this->headerTambahan = [];
        $ini = PerangkatTepercaya::query()->where('pengguna_id', $akun->id)->whereKeyNot($lain->id)->sole();
        $this->assertSame('Chrome di Windows', $ini->nama_perangkat);

        $daftar = $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/saya/perangkat')->assertOk(), 'GET', '/saya/perangkat');
        $this->assertSame([$ini->id, $lain->id], $daftar->json('data.*.id'));
        $this->assertSame([true, false], $daftar->json('data.*.perangkat_ini'));
        $this->assertStringNotContainsString('token', (string) $daftar->getContent());

        // Cabut perangkat lain: sesi tetap; cabut perangkat sesi ini: cookie ikut dihapus.
        $this->assertSesuaiKontrak($this->minta('POST', "/api/v1/saya/perangkat/{$lain->id}/cabut")->assertOk()
            ->assertJsonPath('data.status', 'dicabut')->assertJsonPath('data.alasan_cabut', 'dicabut_pemilik'), 'POST', '/saya/perangkat/{id}/cabut');
        $this->minta('POST', "/api/v1/saya/perangkat/{$lain->id}/cabut")->assertStatus(409)->assertJsonPath('error.kode', 'perangkat_tidak_aktif');
        $this->minta('POST', "/api/v1/saya/perangkat/{$ini->id}/cabut")->assertOk()->assertJsonPath('data.perangkat_ini', true);
        $this->assertArrayNotHasKey($this->cookie(), $this->peramban());
        $this->minta('GET', '/api/v1/saya')->assertOk();
        $this->assertSame([], $this->minta('GET', '/api/v1/saya/perangkat')->json('data'));
        $this->assertCount(2, $this->minta('GET', '/api/v1/saya/perangkat?status=semua')->json('data'));

        // Admin: lihat dan cabut beralasan; perangkat akun lain → 404; akun sendiri → 403.
        $this->mulaiSesiSpa();
        $this->masukDenganMfa($akun, $rahasia, percayai: true);
        $baru = PerangkatTepercaya::query()->where('pengguna_id', $akun->id)->whereNull('dicabut_pada')->sole();
        $pengelola = $this->pengelola();
        $this->masukSebagai($pengelola);
        $this->assertSesuaiKontrak($this->minta('GET', "/api/v1/akun/{$akun->id}/perangkat")->assertOk()
            ->assertJsonPath('data.0.perangkat_ini', false), 'GET', '/akun/{id}/perangkat');
        $this->minta('POST', "/api/v1/akun/{$pengelola->id}/perangkat/{$baru->id}/cabut", ['alasan' => 'Laptop dipinjam'])->assertForbidden();
        $this->minta('POST', "/api/v1/akun/{$pengelola->id}/perangkat/{$baru->id}/cabut", ['alasan' => 'Laptop dipinjam'])
            ->assertJsonPath('error.kode', 'diri_sendiri');
        $cabut = $this->minta('POST', "/api/v1/akun/{$akun->id}/perangkat/{$baru->id}/cabut", ['alasan' => 'Laptop dilaporkan hilang'])->assertOk();
        $this->assertSesuaiKontrak($cabut, 'POST', '/akun/{id}/perangkat/{perangkatId}/cabut');
        $cabut->assertJsonPath('data.alasan_cabut', 'dicabut_admin')->assertJsonPath('data.keterangan_cabut', 'Laptop dilaporkan hilang')
            ->assertJsonPath('data.dicabut_oleh.id', $pengelola->id);
        [$orangLain] = $this->akunBerMfa();
        $this->minta('POST', "/api/v1/akun/{$orangLain->id}/perangkat/{$baru->id}/cabut", ['alasan' => 'Bukan miliknya'])->assertNotFound();
    }

    public function test_kebijakan_keamanan_dan_masa_percaya_peran(): void
    {
        [$superadmin, $rahasia] = $this->superadminBerMfa();
        $this->masukSebagai($superadmin, $rahasia);

        $kebijakan = $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/kebijakan-keamanan')->assertOk(), 'GET', '/kebijakan-keamanan');
        $kebijakan->assertJsonPath('data.perangkat_tepercaya_hari', 30)
            ->assertJsonPath('data.batas', ['maks_hari' => 30, 'maks_hari_superadmin' => 7, 'maks_hari_peran_sensitif' => 14]);
        $versi = $kebijakan->json('data.versi');
        $this->minta('PUT', '/api/v1/kebijakan-keamanan', ['perangkat_tepercaya_hari' => 20, 'dasar_perubahan' => self::DASAR])
            ->assertStatus(428);
        $this->headerTambahan = ['If-Match' => '"'.$versi.'"'];
        $this->minta('PUT', '/api/v1/kebijakan-keamanan', ['perangkat_tepercaya_hari' => 31, 'dasar_perubahan' => self::DASAR])
            ->assertStatus(422)->assertJsonPath('error.kode', 'masa_percaya_melebihi_batas');
        $ubah = $this->minta('PUT', '/api/v1/kebijakan-keamanan', ['perangkat_tepercaya_hari' => 20, 'dasar_perubahan' => self::DASAR])->assertOk();
        $this->assertSesuaiKontrak($ubah, 'PUT', '/kebijakan-keamanan');
        $ubah->assertJsonPath('data.perangkat_tepercaya_hari', 20);
        $audit = DB::table('audit.log_aktivitas')->where('aksi', 'akses.kebijakan_keamanan_diubah')->orderByDesc('urutan')->first();
        $this->assertEquals(['perangkat_tepercaya_hari' => 30], json_decode($audit->sebelum, true));
        $this->minta('PUT', '/api/v1/kebijakan-keamanan', ['perangkat_tepercaya_hari' => 10, 'dasar_perubahan' => self::DASAR])
            ->assertStatus(412);

        // Tanpa izin keamanan → 403.
        $this->headerTambahan = [];
        $this->masukSebagai($this->pengelola());
        $this->minta('GET', '/api/v1/kebijakan-keamanan')->assertForbidden();

        // Peran: batas per jenis, rinci memuat nilai instalasi.
        $peran = $this->buatPeran(['igd.papan']);
        $this->masukSebagai($superadmin, $rahasia);
        $rinci = $this->minta('GET', "/api/v1/peran/{$peran->id}")->assertOk()
            ->assertJsonPath('data.masa_percaya_hari', null)->assertJsonPath('data.batas_masa_percaya_hari', 30)
            ->assertJsonPath('data.masa_percaya_instalasi_hari', 20);
        $this->headerTambahan = ['If-Match' => '"'.$rinci->json('data.versi').'"'];
        $this->minta('PATCH', "/api/v1/peran/{$peran->id}", ['masa_percaya_hari' => 31, 'dasar_perubahan' => self::DASAR])
            ->assertStatus(422)->assertJsonPath('error.kode', 'masa_percaya_melebihi_batas');
        $ubahPeran = $this->minta('PATCH', "/api/v1/peran/{$peran->id}", ['masa_percaya_hari' => 5, 'dasar_perubahan' => self::DASAR])->assertOk();
        $this->assertSesuaiKontrak($ubahPeran, 'PATCH', '/peran/{id}');
        $ubahPeran->assertJsonPath('data.masa_percaya_hari', 5);
        $this->assertSame(5, Peran::query()->findOrFail($peran->id)->masa_percaya_hari);
        // Batas per jenis peran: memuat izin sensitif 14, superadmin 7.
        $this->assertSame(14, KelolaPerangkat::batasPeran(new Peran(['kode' => 'peran_sensitif']), true));
        $this->assertSame(7, KelolaPerangkat::batasPeran($this->superadmin(), true));
    }
}
