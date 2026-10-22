<?php

namespace App\Modules\Pendaftaran;

use App\Modules\Pendaftaran\Console\BatalkanKunjunganTakDilayani;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([BatalkanKunjunganTakDilayani::class]);
        }
    }
}
