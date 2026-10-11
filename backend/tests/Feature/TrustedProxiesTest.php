<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_uji/ip', fn (Request $request) => ['ip' => $request->ip()]);
    }

    public function test_tanpa_konfigurasi_header_forwarded_diabaikan(): void
    {
        config(['jaringan.trusted_proxies' => []]);

        $this->ipDari('10.0.0.1', '203.0.113.7')->assertJson(['ip' => '10.0.0.1']);
    }

    public function test_ip_klien_dari_trusted_proxies(): void
    {
        config(['jaringan.trusted_proxies' => ['10.0.0.0/24']]);

        $this->ipDari('10.0.0.1', '203.0.113.7')->assertJson(['ip' => '203.0.113.7']);
    }

    public function test_header_forwarded_dari_alamat_lain_tidak_dipercaya(): void
    {
        config(['jaringan.trusted_proxies' => ['10.0.0.0/24']]);

        $this->ipDari('198.51.100.9', '203.0.113.7')->assertJson(['ip' => '198.51.100.9']);
    }

    private function ipDari(string $alamatKoneksi, string $forwardedFor): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $alamatKoneksi])
            ->withHeader('X-Forwarded-For', $forwardedFor)
            ->getJson('/_uji/ip');
    }
}
