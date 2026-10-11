<?php

namespace Tests\Feature;

use App\Modules\Akses\Models\Pengguna;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/** PRD 14.1.1 butir 7: rate limit per pengguna untuk semua API. */
class RateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rute contoh hanya ada di tes; satu memakai batas bawaan grup api, satu dilonggarkan.
        Route::middleware('api')->group(function () {
            Route::get('api/v1/uji-rate-limit', fn () => ['ok' => true]);
            Route::get('api/v1/uji-layar-kerja', fn () => ['ok' => true])
                ->withoutMiddleware('throttle:api')
                ->middleware('throttle:api-layar-kerja');
        });
    }

    public function test_melewati_batas_per_pengguna_429_dengan_retry_after(): void
    {
        config(['rate_limit.api.per_pengguna_per_menit' => 3]);
        $this->actingAs(Pengguna::factory()->create(), 'web');

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/uji-rate-limit')->assertOk();
        }

        $respons = $this->getJson('/api/v1/uji-rate-limit')
            ->assertStatus(429)
            ->assertJsonPath('error.kode', 'terlalu_banyak_permintaan');
        $this->assertGreaterThan(0, (int) $respons->headers->get('Retry-After'));
    }

    public function test_batas_dihitung_per_akun_bukan_per_ip(): void
    {
        config(['rate_limit.api.per_pengguna_per_menit' => 2]);

        $this->actingAs(Pengguna::factory()->create(), 'web');
        $this->getJson('/api/v1/uji-rate-limit')->assertOk();
        $this->getJson('/api/v1/uji-rate-limit')->assertOk();
        $this->getJson('/api/v1/uji-rate-limit')->assertStatus(429);

        // Pengguna lain dari IP yang sama (komputer ruangan di balik satu NAT) tidak ikut tertahan.
        $this->actingAs(Pengguna::factory()->create(), 'web');
        $this->getJson('/api/v1/uji-rate-limit')->assertOk();
    }

    public function test_tanpa_sesi_dihitung_per_ip(): void
    {
        config(['rate_limit.api.per_ip_per_menit' => 2]);

        $this->getJson('/api/v1/uji-rate-limit')->assertOk();
        $this->getJson('/api/v1/uji-rate-limit')->assertOk();
        $this->getJson('/api/v1/uji-rate-limit')->assertStatus(429);
        $this->getJson('/api/v1/uji-rate-limit', ['REMOTE_ADDR' => '10.9.8.7'])->assertOk();
    }

    public function test_layar_kerja_bisa_dilonggarkan_per_rute(): void
    {
        config(['rate_limit.api.per_pengguna_per_menit' => 2, 'rate_limit.layar_kerja.per_pengguna_per_menit' => 5]);
        $this->actingAs(Pengguna::factory()->create(), 'web');

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/uji-layar-kerja')->assertOk();
        }
        $this->getJson('/api/v1/uji-layar-kerja')->assertStatus(429);
    }

    public function test_semua_rute_api_v1_berrate_limit(): void
    {
        $router = app('router');
        $tanpaBatas = collect($router->getRoutes()->getRoutes())
            ->filter(fn ($rute) => Str::startsWith($rute->uri(), 'api/v1/'))
            ->reject(fn ($rute) => collect($router->gatherRouteMiddleware($rute))
                ->contains(fn ($m) => is_string($m) && Str::startsWith($m, ThrottleRequests::class)))
            ->map(fn ($rute) => $rute->uri())
            ->values()
            ->all();

        $this->assertSame([], $tanpaBatas, 'Rute api/v1 tanpa rate limit.');
    }
}
