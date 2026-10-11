<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rantai audit (ADR 0003). Butuh cron `* * * * * php artisan schedule:run` di server.
Schedule::command('audit:siapkan-partisi')->dailyAt('02:00')->timezone(config('audit.zona_waktu'))->onOneServer();
Schedule::command('audit:ekspor-anchor')->hourlyAt(5)->onOneServer();
