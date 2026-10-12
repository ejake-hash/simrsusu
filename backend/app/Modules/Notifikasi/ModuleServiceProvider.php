<?php

namespace App\Modules\Notifikasi;

use App\Modules\Akses\Application\JumlahTugasSaya;
use App\Modules\Akses\Events\AkunTerkunci;
use App\Modules\Akses\Events\PermintaanAksesDibuat;
use App\Modules\Akses\Events\PermintaanAksesDitutup;
use App\Modules\Akses\Events\PersetujuanDiajukan;
use App\Modules\Akses\Events\PersetujuanDitutup;
use App\Modules\MasterData\Events\UsulanTahapBerubah;
use App\Modules\Notifikasi\Application\KueriKotakKerja;
use App\Modules\Notifikasi\Application\PendengarKejadian;
use App\Modules\Notifikasi\Console\PeriksaIzinPraktik;
use App\Modules\Notifikasi\Console\ProsesEskalasi;
use App\Modules\Notifikasi\Console\SiapkanPartisi;
use App\Modules\Notifikasi\Domain\PengirimPesanSingkat;
use App\Modules\Notifikasi\Infrastructure\PesanSingkatLog;
use App\Modules\Sdm\Events\IzinPraktikDibatalkan;
use App\Modules\Sdm\Events\IzinPraktikDicatat;
use App\Modules\Ttd\Events\TtdDicabut;
use App\Modules\Ttd\Events\TtdTerkunci;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Modul 53 Notifikasi & kotak kerja (tiket 1.7). */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // WA/SMS lewat adapter; fase 1 driver log saja (K2).
        $this->app->bind(PengirimPesanSingkat::class, PesanSingkatLog::class);
        // Lencana menu Beranda (MenuSaya.jumlah_tugas).
        $this->app->bind(JumlahTugasSaya::class, KueriKotakKerja::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        Event::listen(PersetujuanDiajukan::class, [PendengarKejadian::class, 'persetujuanDiajukan']);
        Event::listen(PersetujuanDitutup::class, [PendengarKejadian::class, 'persetujuanDitutup']);
        Event::listen(PermintaanAksesDibuat::class, [PendengarKejadian::class, 'permintaanDibuat']);
        Event::listen(PermintaanAksesDitutup::class, [PendengarKejadian::class, 'permintaanDitutup']);
        Event::listen(AkunTerkunci::class, [PendengarKejadian::class, 'akunTerkunci']);
        Event::listen(IzinPraktikDicatat::class, [PendengarKejadian::class, 'izinPraktikDicatat']);
        Event::listen(IzinPraktikDibatalkan::class, [PendengarKejadian::class, 'izinPraktikDibatalkan']);
        Event::listen(TtdTerkunci::class, [PendengarKejadian::class, 'ttdTerkunci']);
        Event::listen(UsulanTahapBerubah::class, [PendengarKejadian::class, 'usulanTahapBerubah']);
        Event::listen(TtdDicabut::class, [PendengarKejadian::class, 'ttdDicabut']);

        if ($this->app->runningInConsole()) {
            $this->commands([ProsesEskalasi::class, SiapkanPartisi::class, PeriksaIzinPraktik::class]);
        }
    }
}
