<?php

namespace App\Providers;

use App\Core\Instalasi\StatusInstalasi;
use App\Core\Instalasi\StatusPenyiapan;
use App\Core\Log\InstallMasking;
use App\Core\PemeriksaanProduksi;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Penjaga siap melayani dari wizard penyiapan (tiket 1.8f).
        $this->app->bindIf(StatusInstalasi::class, StatusPenyiapan::class);
    }

    public function boot(): void
    {
        PemeriksaanProduksi::periksa(
            $this->app->environment(),
            (bool) config('app.debug'),
            (bool) config('session.secure'),
            fn (string $kelas) => class_exists($kelas),
        );

        // Argumen fungsi tidak ikut di jejak tumpukan (bisa berisi NIK, nama, dsb.).
        ini_set('zend.exception_ignore_args', '1');

        // Semua channel log, termasuk yang ditambah kemudian di config/logging.php.
        foreach (array_keys(config('logging.channels')) as $channel) {
            config(["logging.channels.{$channel}.tap" => array_values(array_unique([
                ...(array) config("logging.channels.{$channel}.tap", []),
                InstallMasking::class,
            ]))]);
        }

        $this->loadMigrationsFrom([
            app_path('Core/Audit/Database/migrations'), app_path('Core/Modul/Database/migrations'), app_path('Core/ProfilRs/Database/migrations'),
            app_path('Core/Instalasi/Database/migrations'),
        ]);
        $this->loadRoutesFrom(app_path('Core/Http/routes.php'));
        $this->loadRoutesFrom(app_path('Core/ProfilRs/routes.php'));
        $this->loadRoutesFrom(app_path('Core/Instalasi/routes.php'));

        // throttle:api dipasang ke grup `api` di bootstrap/app.php; nilai di config/rate_limit.php.
        RateLimiter::for('api', fn (Request $request) => self::batasPerPengguna(
            $request, (int) config('rate_limit.api.per_pengguna_per_menit'), (int) config('rate_limit.api.per_ip_per_menit'),
        ));
        RateLimiter::for('api-layar-kerja', fn (Request $request) => self::batasPerPengguna(
            $request, (int) config('rate_limit.layar_kerja.per_pengguna_per_menit'), (int) config('rate_limit.api.per_ip_per_menit'),
        ));
        RateLimiter::for('siap', fn (Request $request) => Limit::perMinute((int) config('rate_limit.siap.per_ip_per_menit'))
            ->by('ip:'.$request->ip()));

        // Kolom standar tabel domain (PRD 11.1): dibuat_pada, dibuat_oleh, diubah_pada, diubah_oleh, revisi.
        Blueprint::macro('kolomStandar', function () {
            /** @var Blueprint $this */
            $this->timestampTz('dibuat_pada')->useCurrent();
            $this->uuid('dibuat_oleh')->nullable()->comment('akses.pengguna.id');
            $this->timestampTz('diubah_pada')->useCurrent();
            $this->uuid('diubah_oleh')->nullable()->comment('akses.pengguna.id');
            $this->integer('revisi')->default(0)->comment('Penguncian optimistis');
        });
    }

    /** Per akun bila sudah masuk (banyak pengguna bisa berbagi IP di jaringan RS), selain itu per IP. */
    private static function batasPerPengguna(Request $request, int $perPengguna, int $perIp): Limit
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null
            ? Limit::perMinute($perPengguna)->by('pengguna:'.$id)
            : Limit::perMinute($perIp)->by('ip:'.$request->ip());
    }
}
