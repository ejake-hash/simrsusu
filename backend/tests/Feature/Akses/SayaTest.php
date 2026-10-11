<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\PenggunaPeran;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.2e-be: GET /api/v1/saya untuk Beranda (kontrak skema Saya). */
class SayaTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    public function test_masuk_pertama_lalu_masuk_kedua_menampilkan_masuk_sebelumnya(): void
    {
        // Wajib MFA agar langkah masuk sama dari dalam maupun luar jaringan RS.
        $akun = $this->buatPengguna(['wajib_mfa' => true]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $akun->forceFill(['mfa_rahasia_enkripsi' => Totp::rahasiaBaru(), 'mfa_aktif_pada' => now()])->save();
        $this->masukSebagai($akun);

        $pertama = $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/saya')->assertOk(), 'GET', '/saya');
        $pertama->assertJsonPath('data.masuk_sebelumnya_pada', null)
            ->assertJsonPath('data.masuk_sebelumnya_dari_jaringan_rs', null)
            ->assertJsonMissingPath('data.email');
        $waktuPertama = $pertama->json('data.login_terakhir_pada');

        $this->travel(2)->hours();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->masukSebagai($akun);

        $kedua = $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/saya')->assertOk(), 'GET', '/saya');
        // Masuk sebelumnya = masuk pertama (dari luar jaringan RS), bukan waktu sekarang.
        $kedua->assertJsonPath('data.masuk_sebelumnya_pada', $waktuPertama)
            ->assertJsonPath('data.masuk_sebelumnya_dari_jaringan_rs', false);
        $this->assertNotSame($waktuPertama, $kedua->json('data.login_terakhir_pada'));

        $this->travel(1)->hours();
        $this->masukSebagai($akun);
        $this->minta('GET', '/api/v1/saya')->assertJsonPath('data.masuk_sebelumnya_dari_jaringan_rs', true);
    }

    public function test_peran_berlaku_tanpa_duplikat_urut_nama_dan_respons_masuk_sama(): void
    {
        $akun = $this->buatPengguna();
        $zebra = $this->buatPeran(['igd.papan'], ['nama' => 'Zebra Perawat']);
        $alfa = $this->buatPeran(['igd.triase'], ['nama' => 'Alfa Dokter']);
        $this->tugaskan($akun, $zebra);
        $this->tugaskan($akun, $zebra);
        $this->tugaskan($akun, $alfa);
        $kedaluwarsa = $this->buatPeran(['kasir.tagihan'], ['nama' => 'Kasir Lama']);
        PenggunaPeran::query()->create([
            'pengguna_id' => $akun->id, 'peran_id' => $kedaluwarsa->id, 'dasar_pemberian' => 'SK lama',
            'berlaku_dari' => now()->subDays(2), 'berlaku_sampai' => now()->subDay(),
        ]);

        $this->mulaiSesiSpa();
        $masuk = $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $akun->nama_pengguna, 'sandi' => self::SANDI_UJI])->assertOk();
        $saya = $this->minta('GET', '/api/v1/saya')->assertOk();

        $this->assertSame([['id' => $alfa->id, 'nama' => 'Alfa Dokter'], ['id' => $zebra->id, 'nama' => 'Zebra Perawat']], $saya->json('data.peran'));
        $this->assertSame($saya->json('data'), $masuk->json('pengguna'));
    }

    public function test_akun_tanpa_penugasan_dan_tanpa_data_yang_tidak_dipakai_layar(): void
    {
        $akun = $this->buatPengguna(['email' => 'rahasia@contoh.invalid']);
        $this->mulaiSesiSpa();
        $masuk = $this->minta('POST', '/api/v1/autentikasi/masuk', ['nama_pengguna' => $akun->nama_pengguna, 'sandi' => self::SANDI_UJI])->assertOk();
        $saya = $this->minta('GET', '/api/v1/saya')->assertOk()->assertJsonPath('data.peran', []);

        // Skema kontrak tidak melarang field tambahan, jadi ketiadaannya diperiksa langsung.
        foreach ([(string) $masuk->getContent(), (string) $saya->getContent()] as $isi) {
            $this->assertStringNotContainsString('email', $isi);
            $this->assertStringNotContainsString('rahasia@contoh.invalid', $isi);
            $this->assertStringNotContainsString('instalasi_demo', $isi);
        }
    }

    public function test_tanpa_sesi_401_sesuai_kontrak(): void
    {
        $this->mulaiSesiSpa();
        $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/saya')->assertUnauthorized(), 'GET', '/saya');
    }
}
