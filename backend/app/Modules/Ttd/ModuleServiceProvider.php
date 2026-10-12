<?php

namespace App\Modules\Ttd;

use App\Modules\Akses\Application\PencabutTtd;
use App\Modules\Akses\Application\StatusTtdSaya;
use App\Modules\Ttd\Application\DaftarPenyediaIsi;
use App\Modules\Ttd\Application\KelolaTtd;
use App\Modules\Ttd\Application\PencabutKredensial;
use App\Modules\Ttd\Domain\PenyediaTte;
use App\Modules\Ttd\Infrastructure\IsiPersetujuanAkses;
use App\Modules\Ttd\Infrastructure\SimulatorTte;
use Illuminate\Support\ServiceProvider;

/** Modul 57 Tanda tangan elektronik (tiket 1.6): T1 penuh, T2/PSrE hanya antarmuka + simulator (K10). */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Penyedia isi per jenis objek; jenis baru (mis. usulan_data_induk, 1.8c) ditambahkan di sini.
        $this->app->bind(DaftarPenyediaIsi::class, fn ($app) => new DaftarPenyediaIsi([$app->make(IsiPersetujuanAkses::class)]));
        $this->app->bind(StatusTtdSaya::class, KelolaTtd::class);
        $this->app->bind(PencabutTtd::class, PencabutKredensial::class);
        // Fase 1 tanpa PSrE nyata: hanya simulator (K10).
        $this->app->bind(PenyediaTte::class, SimulatorTte::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
