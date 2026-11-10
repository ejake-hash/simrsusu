<?php

namespace App\Modules\RawatInap;

use App\Modules\MasterData\Domain\PemeriksaPenempatan;
use App\Modules\Pendaftaran\Events\DisposisiDicatat;
use App\Modules\Pendaftaran\Events\DisposisiRawatInap;
use App\Modules\Pendaftaran\Events\KunjunganDibatalkan;
use App\Modules\RawatInap\Application\PemeriksaPenempatanTempatTidur;
use App\Modules\RawatInap\Application\PermintaanKamar;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Modul 08 Rawat Inap (PRD 10.3): peta dan status tempat tidur (tiket 2.27); SPRI, antrean permintaan kamar, alokasi TT
 * (2.28); penempatan, pindah ruang, episode rawat inap (2.29); pulang menyusul.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Penutupan TT (MasterData, 1.8b) bertanya ke penempatan pasien yang nyata (2.29).
        $this->app->bind(PemeriksaPenempatan::class, PemeriksaPenempatanTempatTidur::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        // Sinkron di dalam transaksi pemanggil: permintaan kamar lahir dan batal bersama kejadiannya.
        Event::listen(DisposisiRawatInap::class, [PermintaanKamar::class, 'dariDisposisi']);
        Event::listen(DisposisiDicatat::class, [PermintaanKamar::class, 'disposisiDicatat']);
        Event::listen(KunjunganDibatalkan::class, [PermintaanKamar::class, 'kunjunganDibatalkan']);
    }
}
