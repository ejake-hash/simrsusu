<?php

namespace App\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Cookie sesi wajib Secure di luar lokal (PRD 14.2). Gagal saat boot, bukan diam-diam.
        if (! $this->app->environment('local') && ! config('session.secure')) {
            throw new RuntimeException('SESSION_SECURE_COOKIE harus true di luar lingkungan lokal.');
        }

        $this->loadMigrationsFrom(app_path('Core/Audit/Database/migrations'));

        // Kolom standar tabel domain (PRD 11.1): dibuat_pada, dibuat_oleh, diubah_pada, diubah_oleh, revisi.
        Blueprint::macro('kolomStandar', function () {
            /** @var Blueprint $this */
            $this->timestampTz('dibuat_pada')->useCurrent();
            $this->uuid('dibuat_oleh')->nullable()->comment('akses.pengguna.id');
            $this->timestampTz('diubah_pada')->useCurrent();
            $this->uuid('diubah_oleh')->nullable()->comment('akses.pengguna.id');
            $this->integer('revisi')->default(0)->comment('Penguncian optimistis');
        });
    }
}
