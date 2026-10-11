<?php

namespace Tests\Feature;

use App\Core\Antrean\JenisAntrean;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\PekerjaanUjiAntrean;
use Tests\TestCase;

/** PRD 14.1.1 butir 4: antrean per jenis dengan pekerja masing-masing. */
class AntreanPerJenisTest extends TestCase
{
    public function test_setiap_jenis_punya_supervisor_sendiri_dengan_batas_waktu_di_bawah_retry_after(): void
    {
        $supervisor = config('horizon.defaults');

        $antrean = collect($supervisor)->pluck('queue')->all();
        $this->assertSame(array_map(fn (JenisAntrean $j) => [$j->value], JenisAntrean::cases()), $antrean,
            'Satu supervisor untuk tepat satu jenis antrean; pekerja tidak dipakai bersama.');

        foreach (JenisAntrean::cases() as $jenis) {
            $konfig = $supervisor['antrean-'.$jenis->value];
            $this->assertSame($jenis->koneksi(), $konfig['connection']);
            $this->assertSame($jenis->batasWaktu(), $konfig['timeout']);
            $this->assertLessThan(config("queue.connections.{$jenis->koneksi()}.retry_after"), $konfig['timeout'],
                "retry_after koneksi {$jenis->koneksi()} harus lebih lama dari batas waktu {$jenis->value}.");
        }
    }

    public function test_integrasi_yang_macet_tidak_menunda_notifikasi(): void
    {
        $macet = 'uji-antrean:'.Str::uuid().':integrasi';
        $cepat = 'uji-antrean:'.Str::uuid().':notifikasi';
        $pekerja = [];

        try {
            $this->masukkan(JenisAntrean::Integrasi, new PekerjaanUjiAntrean($macet, tahan: true));
            $this->masukkan(JenisAntrean::Notifikasi, new PekerjaanUjiAntrean($cepat));

            $pekerja[] = $this->jalankanPekerja(JenisAntrean::Integrasi);
            $pekerja[] = $this->jalankanPekerja(JenisAntrean::Notifikasi);

            $this->assertTrue($this->tunggu(fn () => $this->ada("$macet:mulai") && $this->ada("$cepat:selesai")),
                'Pekerjaan notifikasi seharusnya selesai selagi integrasi macet. Keluaran pekerja: '
                .implode(' | ', array_map(fn (InvokedProcess $p) => $p->output().$p->errorOutput(), $pekerja)));
            $this->assertFalse($this->ada("$macet:selesai"), 'Pekerjaan integrasi seharusnya masih tertahan.');
        } finally {
            Redis::connection()->setex("$macet:lepas", 60, '1');
            foreach ($pekerja as $proses) {
                $proses->signal(SIGTERM);
            }
            $this->tunggu(fn () => collect($pekerja)->every(fn (InvokedProcess $p) => ! $p->running()));
            foreach ([JenisAntrean::Integrasi, JenisAntrean::Notifikasi] as $jenis) {
                $this->antrean($jenis)->clear($jenis->value);
            }
            Redis::connection()->del(...array_map(fn ($a) => "$a[0]:$a[1]", [
                [$macet, 'mulai'], [$macet, 'selesai'], [$macet, 'lepas'], [$cepat, 'mulai'], [$cepat, 'selesai'],
            ]));
        }
    }

    private function masukkan(JenisAntrean $jenis, PekerjaanUjiAntrean $pekerjaan): void
    {
        $this->antrean($jenis)->push($pekerjaan, '', $jenis->value);
    }

    private function antrean(JenisAntrean $jenis): RedisQueue
    {
        $antrean = Queue::connection($jenis->koneksi());
        $this->assertInstanceOf(RedisQueue::class, $antrean);

        return $antrean;
    }

    /** Sama dengan supervisor Horizon untuk jenis ini: satu antrean, batas waktunya sendiri. */
    private function jalankanPekerja(JenisAntrean $jenis): InvokedProcess
    {
        $konfig = config('horizon.defaults.antrean-'.$jenis->value);

        return Process::path(base_path())->start([
            PHP_BINARY, 'artisan', 'queue:work', $konfig['connection'],
            '--queue='.implode(',', $konfig['queue']), '--timeout='.$konfig['timeout'],
            '--tries=1', '--sleep=1', '--max-time=30',
        ]);
    }

    private function ada(string $kunci): bool
    {
        return (bool) Redis::connection()->exists($kunci);
    }

    private function tunggu(callable $syarat, float $detik = 15): bool
    {
        $batas = microtime(true) + $detik;
        while (microtime(true) < $batas) {
            if ($syarat()) {
                return true;
            }
            usleep(100_000);
        }

        return false;
    }
}
