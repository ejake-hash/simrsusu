<?php

namespace App\Modules\RawatInap;

use Illuminate\Support\ServiceProvider;

/** Modul 08 Rawat Inap (PRD 10.3): peta dan status tempat tidur (tiket 2.27); admisi, penempatan, pindah, pulang menyusul. */
class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
    }
}
