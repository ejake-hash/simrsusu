<?php

namespace App\Modules\Lab;

use Illuminate\Support\ServiceProvider;

/** Modul 19 Laboratorium (PRD 10.3): daftar kerja order lab dan spesimen (tiket 2.30). */
class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
    }
}
