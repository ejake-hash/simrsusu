<?php

use App\Modules\Notifikasi\Jobs\ProsesEskalasiTugas;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rantai audit (ADR 0003). Butuh cron `* * * * * php artisan schedule:run` di server.
Schedule::command('audit:siapkan-partisi')->dailyAt('02:00')->timezone(config('audit.zona_waktu'))->onOneServer();
Schedule::command('audit:ekspor-anchor')->hourlyAt(5)->onOneServer();

// Persetujuan akses yang lewat 7 hari tanpa keputusan (tiket 1.2d).
Schedule::command('akses:kedaluwarsakan-persetujuan')->dailyAt('01:00')->timezone(config('instalasi.zona_waktu'))->onOneServer();

// Kapasitas TT harian per bangsal dan kelas, penyebut BOR/BTO/TOI (tiket 1.8b, FR-08-18).
Schedule::command('master-data:rekam-kapasitas-tt')->dailyAt('00:05')->timezone(config('instalasi.zona_waktu'))->onOneServer();

// Pegawai yang tanggal keluarnya tercapai: akun nonaktif, sesi diputus (tiket 1.8d, PRD 9.2.6).
Schedule::command('sdm:proses-pegawai-keluar')->dailyAt('00:10')->timezone(config('instalasi.zona_waktu'))->onOneServer();

// Eskalasi tugas (tiket 1.7, FR-53-03) dan partisi notifikasi bulanan.
Schedule::job(new ProsesEskalasiTugas)->everyMinute()->onOneServer();
Schedule::command('notifikasi:siapkan-partisi')->dailyAt('02:10')->timezone(config('audit.zona_waktu'))->onOneServer();
Schedule::command('notifikasi:periksa-izin-praktik')->dailyAt('06:00')->timezone(config('instalasi.zona_waktu'))->onOneServer();
