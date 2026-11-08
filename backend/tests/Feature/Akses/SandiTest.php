<?php

namespace Tests\Feature\Akses;

use App\Core\Http\ApiError;
use App\Modules\Akses\Application\GantiSandi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Ttd\Application\KelolaTtd;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class SandiTest extends TestCase
{
    public function test_hash_sandi_memakai_argon2id(): void
    {
        $this->assertSame('argon2id', password_get_info(Hash::make('kuda-lumping-biru-29'))['algoName']);
    }

    /**
     * Permintaan paralel dengan sandi salah: semuanya sudah lewat cek batas sebelum yang pertama selesai Argon2. Disimulasikan
     * dengan memanggil ulang dari dalam Hash::check; hitungan harus naik sebelum Argon2 sehingga yang ke-(batas+1) ditolak.
     */
    public function test_batas_sandi_salah_tidak_bisa_ditembus_permintaan_paralel(): void
    {
        $pengguna = Pengguna::factory()->create();
        $panggil = [
            'ganti sandi' => fn () => app(GantiSandi::class)->ganti(request(), $pengguna, 'salah-sandi-lama-99', 'Kuda-Biru-Lompat-77'),
            'aktivasi TTE' => fn () => app(KelolaTtd::class)->aktivasi(request(), $pengguna, 'salah-sandi-lama-99', '135792', '135792'),
        ];
        $argon = 0;
        $ditolak = [];
        $coba = $panggil['ganti sandi'];
        Hash::shouldReceive('check')->andReturnUsing(function () use (&$argon, &$ditolak, &$coba) {
            if (++$argon <= GantiSandi::BATAS_SALAH) {
                try {
                    $coba();
                } catch (ApiError $e) {
                    $ditolak[] = $e->getStatusCode();
                }
            }

            return false;
        });
        foreach ($panggil as $nama => $coba) {
            [$argon, $ditolak] = [0, []];
            try {
                $coba();
            } catch (ApiError) {
            }

            $this->assertSame(GantiSandi::BATAS_SALAH, $argon, "$nama: percobaan yang sampai ke Argon2 melebihi batas.");
            $this->assertContains(429, $ditolak, $nama);
        }
    }

    public function test_sandi_kurang_dari_dua_belas_karakter_ditolak(): void
    {
        $this->assertFalse($this->lolos('Pendek-123!'));
        $this->assertTrue($this->lolos('kuda-lumping-biru-29'));
    }

    public function test_sandi_demo_pendek_hanya_lolos_di_lingkungan_local(): void
    {
        $this->assertFalse($this->lolos('123456'), 'Testing/CI tetap memakai aturan PRD 14.2.');

        try {
            foreach (['staging' => false, 'production' => false, 'local' => true] as $env => $lolos) {
                $this->app['env'] = $env;
                $this->assertSame($lolos, $this->lolos('123456'), "APP_ENV={$env}");
            }
            $this->assertFalse($this->lolos('12345'), 'Lokal pun minimal 6 karakter.');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_ganti_sandi_demo_pendek_hanya_di_lingkungan_local(): void
    {
        $pengguna = Pengguna::factory()->create(['sandi_hash' => Hash::make('sandi-lama-yang-panjang-1')]);
        $request = request();
        $request->setLaravelSession(app('session.store'));
        $ganti = fn () => app(GantiSandi::class)->ganti($request, $pengguna, 'sandi-lama-yang-panjang-1', '123456');

        try {
            $ganti();
            $this->fail('Testing/CI tetap menolak sandi pendek.');
        } catch (ApiError $e) {
            $this->assertSame('validasi_gagal', $e->kode);
        }

        $this->app['env'] = 'local';
        try {
            $ganti();
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertTrue(Hash::check('123456', $pengguna->refresh()->sandi_hash));
    }

    public function test_sandi_dari_daftar_bocor_lokal_ditolak_tanpa_internet(): void
    {
        Http::preventStrayRequests();

        $this->assertFalse($this->lolos('passwordpassword'));
        $this->assertFalse($this->lolos('Indonesia123'), 'Pencocokan tidak membedakan huruf besar/kecil.');
    }

    public function test_cek_hibp_hanya_jalan_bila_diaktifkan(): void
    {
        // SHA-1("kuda-lumping-biru-29") dipotong: 5 karakter awalan dikirim, sisanya dicocokkan lokal.
        $sha1 = strtoupper(sha1('kuda-lumping-biru-29'));
        Http::fake(['api.pwnedpasswords.com/range/'.substr($sha1, 0, 5) => Http::response(substr($sha1, 5).':42')]);

        $this->assertTrue($this->lolos('kuda-lumping-biru-29'));
        Http::assertNothingSent();

        config(['autentikasi.sandi.cek_hibp' => true]);
        $this->assertFalse($this->lolos('kuda-lumping-biru-29'));
    }

    private function lolos(string $sandi): bool
    {
        return Validator::make(['sandi' => $sandi], ['sandi' => Password::defaults()])->passes();
    }
}
