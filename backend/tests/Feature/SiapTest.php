<?php

namespace Tests\Feature;

use Tests\TestCase;

/** PRD 14.1.1 butir 8: /up hidup, /api/v1/siap siap melayani. */
class SiapTest extends TestCase
{
    public function test_up_dan_siap_tanpa_autentikasi(): void
    {
        $this->get('/up')->assertOk();

        $this->getJson('/api/v1/siap')
            ->assertOk()
            ->assertExactJson([
                'status' => 'siap',
                'komponen' => ['basis_data' => 'ok', 'valkey_sesi' => 'ok', 'valkey_cache' => 'ok'],
            ]);
    }

    public function test_valkey_sesi_mati_503_tanpa_rincian_teknis(): void
    {
        $this->matikanValkey('default');

        $respons = $this->getJson('/api/v1/siap')
            ->assertStatus(503)
            ->assertExactJson(['galat' => [
                'kode' => 'layanan_tidak_tersedia',
                'pesan' => 'Layanan sedang tidak tersedia.',
                'rincian' => [['komponen' => 'valkey_sesi', 'status' => 'gagal']],
            ]]);
        $this->assertStringNotContainsString('127.0.0.1', (string) $respons->getContent());
    }

    public function test_valkey_cache_mati_503(): void
    {
        $this->matikanValkey('cache');

        $this->getJson('/api/v1/siap')
            ->assertStatus(503)
            ->assertJsonPath('galat.rincian', [['komponen' => 'valkey_cache', 'status' => 'gagal']]);
    }

    public function test_siap_berbatas_laju_per_ip(): void
    {
        config(['batas_laju.siap.per_ip_per_menit' => 2]);

        $this->getJson('/api/v1/siap')->assertOk();
        $this->getJson('/api/v1/siap')->assertOk();
        $this->getJson('/api/v1/siap')->assertStatus(429)->assertHeader('Retry-After');
    }

    /** Port 1 di loopback: koneksi langsung ditolak, meniru instans yang mati. */
    private function matikanValkey(string $koneksi): void
    {
        config(["database.redis.{$koneksi}.port" => 1, "database.redis.{$koneksi}.url" => null]);
        app('redis')->purge($koneksi);
    }
}
