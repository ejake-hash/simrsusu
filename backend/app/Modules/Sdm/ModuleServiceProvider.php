<?php

namespace App\Modules\Sdm;

use App\Modules\Sdm\Console\ProsesPegawaiKeluar;
use Illuminate\Support\ServiceProvider;

/** Modul 39 SDM: pegawai, izin praktik, kewenangan klinis (tiket 1.8d). */
class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([ProsesPegawaiKeluar::class]);
        }
    }
}
