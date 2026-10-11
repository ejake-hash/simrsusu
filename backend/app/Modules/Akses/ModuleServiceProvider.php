<?php

namespace App\Modules\Akses;

use App\Core\Http\PenggunaSesi;
use App\Modules\Akses\Application\AccessEvaluator;
use App\Modules\Akses\Application\PendaftaranPerangkat;
use App\Modules\Akses\Application\SandiTidakBocor;
use App\Modules\Akses\Console\BuatSuperadminPertama;
use App\Modules\Akses\Console\KedaluwarsakanPersetujuan;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Http\Middleware\SesiPengguna;
use App\Modules\Akses\Infrastructure\DaftarSandiBocor;
use App\Modules\Akses\Infrastructure\PenggunaSesiAkses;
use App\Modules\Akses\Infrastructure\PerangkatBelumTerdaftar;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\TokenAksesPribadi;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PendaftaranPerangkat::class, PerangkatBelumTerdaftar::class);
        $this->app->bind(PenggunaSesi::class, PenggunaSesiAkses::class);
        $this->app->singleton(DaftarSandiBocor::class, fn () => new DaftarSandiBocor(config('autentikasi.sandi.daftar_bocor')));
    }

    public function boot(): void
    {
        // Dipakai rute bersesi di luar modul ini (mis. Core/ProfilRs) tanpa bergantung pada kelasnya.
        $this->app['router']->aliasMiddleware('sesi.pengguna', SesiPengguna::class);
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([BuatSuperadminPertama::class, KedaluwarsakanPersetujuan::class]);
        }

        Sanctum::usePersonalAccessTokenModel(TokenAksesPribadi::class);

        // Semua kemampuan berbentuk kode izin (<menu>.<sub_menu>.<aksi>) diputuskan AccessEvaluator
        // (PRD 9.2.5), mis. ->middleware('can:pengaturan.peran.lihat') atau Gate::allows($kode, $cakupan).
        // Argumen selain Cakupan ditolak sampai pemeriksaan per objek dibuat (tolak kecuali diberikan).
        Gate::before(function (mixed $pengguna, string $kemampuan, array $argumen) {
            if (! str_contains($kemampuan, '.')) {
                return null;
            }
            $data = $argumen[0] ?? null;
            if (! $pengguna instanceof Pengguna || ($data !== null && ! $data instanceof Cakupan)) {
                return false;
            }

            return $this->app->make(AccessEvaluator::class)->boleh($pengguna, $kemampuan, $data);
        });

        // Aturan sandi PRD 14.2; dipakai lewat Password::defaults() di mana pun sandi ditetapkan.
        Password::defaults(function () {
            $aturan = Password::min((int) config('autentikasi.sandi.panjang_minimal'))
                ->rules([$this->app->make(SandiTidakBocor::class)]);

            return config('autentikasi.sandi.cek_hibp') ? $aturan->uncompromised() : $aturan;
        });

        RateLimiter::for('autentikasi-masuk', fn (Request $request) => [
            Limit::perMinute((int) config('autentikasi.rate_limit.per_ip_per_menit'))
                ->by('ip:'.$request->ip()),
            Limit::perMinute((int) config('autentikasi.rate_limit.per_nama_pengguna_per_menit'))
                ->by('nama:'.hash('sha256', mb_strtolower(trim((string) $request->input('nama_pengguna'))))),
        ]);

        // Per sesi, bukan per nama pengguna: langkah MFA tidak membawa nama; challenge terikat ke sesi.
        RateLimiter::for('autentikasi-mfa', fn (Request $request) => [
            Limit::perMinute((int) config('autentikasi.rate_limit.per_ip_per_menit'))
                ->by('ip:'.$request->ip()),
            Limit::perMinute((int) config('autentikasi.rate_limit.per_nama_pengguna_per_menit'))
                // ID sesi asli, bukan nilai cookie: cookie terenkripsi ulang dengan IV baru tiap respons.
                ->by('sesi:'.hash('sha256', $request->hasSession() ? $request->session()->getId() : (string) $request->ip())),
        ]);
    }
}
