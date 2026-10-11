<?php

namespace App\Modules\MasterData;

use App\Modules\Akses\Application\DataUnit;
use App\Modules\MasterData\Application\KapasitasTempatTidur;
use App\Modules\MasterData\Application\PohonUnit;
use App\Modules\MasterData\Console\RekamKapasitasTempatTidur;
use App\Modules\MasterData\Domain\PemeriksaPenempatan;
use App\Modules\MasterData\Domain\PenempatanBelumAda;
use App\Modules\MasterData\Events\KapasitasTempatTidurBerubah;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Modul 54 Master Data: unit organisasi, KSM (1.8a), lokasi fisik, ruang, dan TT (1.8b). Profil RS di App\Core\ProfilRs. */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DataUnit::class, PohonUnit::class);
        // Penempatan pasien dibangun di fase 2; sampai itu TT tidak pernah terisi (tiket 1.8b).
        $this->app->bind(PemeriksaPenempatan::class, PenempatanBelumAda::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        Event::listen(KapasitasTempatTidurBerubah::class, [KapasitasTempatTidur::class, 'tanganiPerubahan']);
        if ($this->app->runningInConsole()) {
            $this->commands([RekamKapasitasTempatTidur::class]);
        }
    }
}
