<?php

namespace App\Modules\Integrasi;

use App\Core\Instalasi\SyaratKredensialIntegrasi;
use App\Modules\Integrasi\Application\DaftarPenangan;
use App\Modules\Integrasi\Application\SyaratPenyiapan;
use App\Modules\Integrasi\Console\HapusLogLama;
use App\Modules\Integrasi\Console\RotasiKunciKredensial;
use App\Modules\Integrasi\Console\SiapkanPartisi;
use App\Modules\Integrasi\Console\SimulatorMode;
use App\Modules\Integrasi\Domain\PenyimpanRahasia;
use App\Modules\Integrasi\Infrastructure\PenyandiKredensial;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** Modul 49–51 Integrasi (tiket 1.9): gateway, outbox, kredensial (K9), simulator (K8). */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Penangan kiriman per tujuan + jenis_resource dipasang di sini oleh tiket yang membangunnya.
        $this->app->singleton(DaftarPenangan::class, fn () => new DaftarPenangan([]));
        $this->app->bind(SyaratKredensialIntegrasi::class, SyaratPenyiapan::class);
        // K9: enkripsi tabel dengan kunci khusus; vault bisa menggantikan binding ini.
        $this->app->bind(PenyimpanRahasia::class, PenyandiKredensial::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        // Uji koneksi memanggil sistem luar langsung: 6 kali per menit per akun (kontrak v0.13.0).
        RateLimiter::for('integrasi-uji-koneksi', fn (Request $request) => Limit::perMinute(6)->by('integrasi-uji:'.($request->user() === null ? $request->ip() : $request->user()->id)));
        if ($this->app->runningInConsole()) {
            $this->commands([RotasiKunciKredensial::class, SiapkanPartisi::class, HapusLogLama::class, SimulatorMode::class]);
        }
    }
}
