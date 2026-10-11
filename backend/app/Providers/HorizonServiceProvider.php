<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Dasbor /horizon menampilkan isi pekerjaan, yang bisa memuat data pasien. Di luar APP_ENV=local
     * ditolak untuk semua akun sampai izinnya lewat AccessEvaluator (tiket 1.2), bukan cek peran di sini.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn () => false);
    }
}
