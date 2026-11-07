<?php

namespace App\Modules\Antrean;

use App\Modules\Antrean\Application\KelolaAntrean;
use App\Modules\Antrean\Console\TokenDisplay;
use App\Modules\Pendaftaran\Events\KunjunganDibatalkan;
use App\Modules\Pendaftaran\Events\KunjunganDibuat;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([TokenDisplay::class]);
        }

        Event::listen(KunjunganDibuat::class, [KelolaAntrean::class, 'buatPoli']);
        Event::listen(KunjunganDibatalkan::class, [KelolaAntrean::class, 'batalkanKunjungan']);

        // Display dihitung per token (banyak TV bisa berbagi satu IP), dengan batas per IP untuk token acak.
        RateLimiter::for('display', fn (Request $request) => [
            Limit::perMinute((int) config('rate_limit.display.per_perangkat_per_menit'))->by('display:'.hash('sha256', (string) $request->bearerToken())),
            Limit::perMinute((int) config('rate_limit.display.per_ip_per_menit'))->by('display-ip:'.$request->ip()),
        ]);
    }
}
