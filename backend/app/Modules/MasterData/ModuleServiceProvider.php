<?php

namespace App\Modules\MasterData;

use App\Modules\Akses\Application\DataUnit;
use App\Modules\MasterData\Application\PohonUnit;
use Illuminate\Support\ServiceProvider;

/** Modul 54 Master Data: unit organisasi dan KSM (tiket 1.8a). Profil RS ada di App\Core\ProfilRs. */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DataUnit::class, PohonUnit::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
