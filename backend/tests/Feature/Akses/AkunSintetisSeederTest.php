<?php

namespace Tests\Feature\Akses;

use App\Modules\Akses\Database\Seeders\AkunSintetisSeeder;
use App\Modules\Akses\Models\Pengguna;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class AkunSintetisSeederTest extends TestCase
{
    public function test_menolak_berjalan_di_production(): void
    {
        // Dipanggil langsung: db:seed milik Laravel sendiri sudah menolak production tanpa --force,
        // yang diuji di sini adalah guard seeder bila --force tetap dipakai.
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->app->make(AkunSintetisSeeder::class)->__invoke();
    }

    public function test_membuat_akun_sintetis_dengan_hash_argon2id(): void
    {
        config(['autentikasi.akun_sintetis_sandi' => 'kuda-lumping-biru-29']);

        $this->seed(AkunSintetisSeeder::class);

        $akun = Pengguna::query()->where('nama_pengguna', 'like', 'contoh.%')->get();
        $this->assertCount(3, $akun);
        foreach ($akun as $pengguna) {
            $this->assertSame('argon2id', password_get_info($pengguna->sandi_hash)['algoName']);
        }
    }

    public function test_menolak_sandi_akun_sintetis_yang_lemah(): void
    {
        config(['autentikasi.akun_sintetis_sandi' => 'passwordpassword']);

        $this->expectException(ValidationException::class);
        $this->seed(AkunSintetisSeeder::class);
    }
}
