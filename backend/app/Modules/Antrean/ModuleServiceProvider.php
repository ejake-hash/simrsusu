<?php

namespace App\Modules\Antrean;

use App\Modules\Antrean\Application\CatatTaskAntrean;
use App\Modules\Antrean\Application\KelolaAntrean;
use App\Modules\Antrean\Console\TokenDisplay;
use App\Modules\Antrean\Events\AntreanBerubah;
use App\Modules\Pendaftaran\Events\KunjunganDibatalkan;
use App\Modules\Pendaftaran\Events\KunjunganDibuat;
use App\Modules\RekamMedis\Events\AsesmenPerawatSelesai;
use App\Modules\RekamMedis\Events\PemeriksaanDimulai;
use App\Modules\RekamMedis\Events\PemeriksaanSelesai;
use App\Modules\RekamMedis\Events\ResepDibatalkan;
use App\Modules\RekamMedis\Events\ResepDisahkan;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([TokenDisplay::class]);
        }

        Event::listen(KunjunganDibuat::class, [KelolaAntrean::class, 'buatPoli']);
        Event::listen(KunjunganDibatalkan::class, [KelolaAntrean::class, 'batalkanKunjungan']);
        Event::listen(AsesmenPerawatSelesai::class, [KelolaAntrean::class, 'keTahapDokter']);
        Event::listen(PemeriksaanSelesai::class, [KelolaAntrean::class, 'selesaikanKunjungan']);
        Event::listen(ResepDisahkan::class, [KelolaAntrean::class, 'buatFarmasi']);
        Event::listen(ResepDibatalkan::class, [KelolaAntrean::class, 'batalFarmasi']);

        // Task ID antrean (tiket 2.18): sesudah listener KelolaAntrean di atas, agar antrean poli/farmasi sudah ada.
        Event::listen(KunjunganDibuat::class, [CatatTaskAntrean::class, 'kunjunganDibuat']);
        Event::listen(AsesmenPerawatSelesai::class, [CatatTaskAntrean::class, 'asesmenPerawatSelesai']);
        Event::listen(PemeriksaanDimulai::class, [CatatTaskAntrean::class, 'pemeriksaanDimulai']);
        Event::listen(PemeriksaanSelesai::class, [CatatTaskAntrean::class, 'pemeriksaanSelesai']);
        Event::listen(ResepDisahkan::class, [CatatTaskAntrean::class, 'resepDisahkan']);
        Event::listen(AntreanBerubah::class, [CatatTaskAntrean::class, 'antreanBerubah']);

        // Display dihitung per token (banyak TV bisa berbagi satu IP), dengan batas per IP untuk token acak.
        RateLimiter::for('display', fn (Request $request) => [
            Limit::perMinute((int) config('rate_limit.display.per_perangkat_per_menit'))->by('display:'.hash('sha256', (string) $request->bearerToken())),
            Limit::perMinute((int) config('rate_limit.display.per_ip_per_menit'))->by('display-ip:'.$request->ip()),
        ]);
    }
}
