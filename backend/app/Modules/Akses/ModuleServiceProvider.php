<?php

namespace App\Modules\Akses;

use App\Modules\Akses\Application\PendaftaranPerangkat;
use App\Modules\Akses\Application\SandiTidakBocor;
use App\Modules\Akses\Console\BuatSuperadminPertama;
use App\Modules\Akses\Infrastructure\DaftarSandiBocor;
use App\Modules\Akses\Infrastructure\PerangkatBelumTerdaftar;
use App\Modules\Akses\Models\TokenAksesPribadi;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PendaftaranPerangkat::class, PerangkatBelumTerdaftar::class);
        $this->app->singleton(DaftarSandiBocor::class, fn () => new DaftarSandiBocor(config('autentikasi.sandi.daftar_bocor')));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([BuatSuperadminPertama::class]);
        }

        Sanctum::usePersonalAccessTokenModel(TokenAksesPribadi::class);

        // Aturan sandi PRD 14.2; dipakai lewat Password::defaults() di mana pun sandi ditetapkan.
        Password::defaults(function () {
            $aturan = Password::min((int) config('autentikasi.sandi.panjang_minimal'))
                ->rules([$this->app->make(SandiTidakBocor::class)]);

            return config('autentikasi.sandi.cek_hibp') ? $aturan->uncompromised() : $aturan;
        });

        RateLimiter::for('autentikasi-masuk', fn (Request $request) => [
            Limit::perMinute((int) config('autentikasi.batas_laju.per_ip_per_menit'))
                ->by('ip:'.$request->ip()),
            Limit::perMinute((int) config('autentikasi.batas_laju.per_nama_pengguna_per_menit'))
                ->by('nama:'.hash('sha256', mb_strtolower(trim((string) $request->input('nama_pengguna'))))),
        ]);

        // Per sesi, bukan per nama pengguna: langkah MFA tidak membawa nama; tantangan terikat ke sesi.
        RateLimiter::for('autentikasi-mfa', fn (Request $request) => [
            Limit::perMinute((int) config('autentikasi.batas_laju.per_ip_per_menit'))
                ->by('ip:'.$request->ip()),
            Limit::perMinute((int) config('autentikasi.batas_laju.per_nama_pengguna_per_menit'))
                // ID sesi asli, bukan nilai cookie: cookie terenkripsi ulang dengan IV baru tiap respons.
                ->by('sesi:'.hash('sha256', $request->hasSession() ? $request->session()->getId() : (string) $request->ip())),
        ]);
    }
}
