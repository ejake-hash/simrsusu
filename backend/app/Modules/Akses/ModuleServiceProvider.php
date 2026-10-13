<?php

namespace App\Modules\Akses;

use App\Core\Http\PenggunaSesi;
use App\Modules\Akses\Application\AccessEvaluator;
use App\Modules\Akses\Application\AkunPegawai;
use App\Modules\Akses\Application\PendaftaranPerangkat;
use App\Modules\Akses\Application\SandiTidakBocor;
use App\Modules\Akses\Console\BuatSuperadminPertama;
use App\Modules\Akses\Console\KedaluwarsakanPersetujuan;
use App\Modules\Akses\Console\ResetSandiKonsol;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Domain\RelasiPerawatan;
use App\Modules\Akses\Domain\TindakanKlinis;
use App\Modules\Akses\Http\Middleware\IzinSeluruhRs;
use App\Modules\Akses\Http\Middleware\SesiPengguna;
use App\Modules\Akses\Infrastructure\DaftarSandiBocor;
use App\Modules\Akses\Infrastructure\PenggunaSesiAkses;
use App\Modules\Akses\Infrastructure\PerangkatTepercayaSesi;
use App\Modules\Akses\Infrastructure\TanpaRelasiPerawatan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\TokenAksesPribadi;
use App\Modules\Sdm\Events\PegawaiKeluar;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PendaftaranPerangkat::class, PerangkatTepercayaSesi::class);
        $this->app->bind(PenggunaSesi::class, PenggunaSesiAkses::class);
        // Fase 1 tanpa modul pelayanan: tidak ada yang merawat pasien; modul pelayanan fase 2 mengganti binding ini.
        $this->app->bind(RelasiPerawatan::class, TanpaRelasiPerawatan::class);
        $this->app->singleton(DaftarSandiBocor::class, fn () => new DaftarSandiBocor(config('autentikasi.sandi.daftar_bocor')));
    }

    public function boot(): void
    {
        // Dipakai rute bersesi di luar modul ini (mis. Core/ProfilRs) tanpa bergantung pada kelasnya.
        $this->app['router']->aliasMiddleware('sesi.pengguna', SesiPengguna::class);
        $this->app['router']->aliasMiddleware('can.rs', IzinSeluruhRs::class);
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([BuatSuperadminPertama::class, KedaluwarsakanPersetujuan::class, ResetSandiKonsol::class]);
        }

        Sanctum::usePersonalAccessTokenModel(TokenAksesPribadi::class);

        // Pegawai keluar (modul SDM) → akun nonaktif dan sesi diputus, di dalam transaksi yang sama (PRD 9.2.6).
        Event::listen(PegawaiKeluar::class, [AkunPegawai::class, 'nonaktifkanKarenaKeluar']);

        // Semua kemampuan berbentuk kode izin (<menu>.<sub_menu>.<aksi>) diputuskan AccessEvaluator
        // (PRD 9.2.5), mis. ->middleware('can:sdm.pegawai.lihat'), ->middleware('can.rs:pengaturan.peran.lihat') (wajib
        // cakupan seluruh RS, IzinSeluruhRs), Gate::allows($kode, $cakupan), atau Gate::allows($kode, [$cakupan, new
        // TindakanKlinis($kode)]) untuk RKK (lapis 5). Argumen lain ditolak sampai pemeriksaan per objek dibuat (tolak
        // kecuali diberikan). Penolakan lapis 5 membawa kode error (ErrorHandler).
        Gate::before(function (mixed $pengguna, string $kemampuan, array $argumen) {
            if (! str_contains($kemampuan, '.')) {
                return null;
            }
            if (! $pengguna instanceof Pengguna) {
                return false;
            }
            $data = $tindakan = null;
            foreach ($argumen as $a) {
                if ($a instanceof Cakupan && $data === null) {
                    $data = $a;
                } elseif ($a instanceof TindakanKlinis && $tindakan === null) {
                    $tindakan = $a;
                } elseif ($a !== null) {
                    return false;
                }
            }

            return $this->app->make(AccessEvaluator::class)->periksa($pengguna, $kemampuan, $data, $tindakan);
        });

        // Aturan sandi PRD 14.2; dipakai lewat Password::defaults() di mana pun sandi ditetapkan.
        Password::defaults(function () {
            // PRD 14.2: minimal 12, walau konfigurasi diisi lebih kecil.
            $aturan = Password::min(max(12, (int) config('autentikasi.sandi.panjang_minimal')))
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
