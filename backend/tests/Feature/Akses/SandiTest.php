<?php

namespace Tests\Feature\Akses;

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

    public function test_sandi_kurang_dari_dua_belas_karakter_ditolak(): void
    {
        $this->assertFalse($this->lolos('Pendek-123!'));
        $this->assertTrue($this->lolos('kuda-lumping-biru-29'));
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
