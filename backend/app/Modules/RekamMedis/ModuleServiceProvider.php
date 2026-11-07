<?php

namespace App\Modules\RekamMedis;

use App\Modules\RekamMedis\Console\SiapkanPartisi;
use Illuminate\Support\ServiceProvider;

/** Modul 26 Rekam Medis: catatan klinis, observasi, alergi (tiket 2.13); CPPT episode menyusul (2.14). */
class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([SiapkanPartisi::class]);
        }
    }
}
