<?php

namespace Tests\Feature;

use App\Core\Queue\JenisQueue;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\PekerjaanUjiQueue;
use Tests\TestCase;

/** PRD 14.1.1 butir 4: queue per jenis dengan pekerja masing-masing. */
class QueuePerJenisTest extends TestCase
{
    public function test_setiap_jenis_punya_supervisor_sendiri_dengan_batas_waktu_di_bawah_retry_after(): void
    {
        $supervisor = config('horizon.defaults');

        $queue = collect($supervisor)->pluck('queue')->all();
        $this->assertSame(array_map(fn (JenisQueue $j) => [$j->value], JenisQueue::cases()), $queue,
            'Satu supervisor untuk tepat satu jenis queue; pekerja tidak dipakai bersama.');

        foreach (JenisQueue::cases() as $jenis) {
            $konfig = $supervisor['queue-'.$jenis->value];
            $this->assertSame($jenis->koneksi(), $konfig['connection']);
            $this->assertSame($jenis->batasWaktu(), $konfig['timeout']);
            $this->assertLessThan(config("queue.connections.{$jenis->koneksi()}.retry_after"), $konfig['timeout'],
                "retry_after koneksi {$jenis->koneksi()} harus lebih lama dari batas waktu {$jenis->value}.");
        }
    }

    public function test_integrasi_yang_macet_tidak_menunda_notifikasi(): void
    {
        $macet = 'uji-queue:'.Str::uuid().':integrasi';
        $cepat = 'uji-queue:'.Str::uuid().':notifikasi';
        $pekerja = [];

        try {
            $this->masukkan(JenisQueue::Integrasi, new PekerjaanUjiQueue($macet, tahan: true));
            $this->masukkan(JenisQueue::Notifikasi, new PekerjaanUjiQueue($cepat));

            $pekerja[] = $this->jalankanPekerja(JenisQueue::Integrasi);
            $pekerja[] = $this->jalankanPekerja(JenisQueue::Notifikasi);

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
            foreach ([JenisQueue::Integrasi, JenisQueue::Notifikasi] as $jenis) {
                $this->queue($jenis)->clear($jenis->value);
            }
            Redis::connection()->del(...array_map(fn ($a) => "$a[0]:$a[1]", [
                [$macet, 'mulai'], [$macet, 'selesai'], [$macet, 'lepas'], [$cepat, 'mulai'], [$cepat, 'selesai'],
            ]));
        }
    }

    private function masukkan(JenisQueue $jenis, PekerjaanUjiQueue $pekerjaan): void
    {
        $this->queue($jenis)->push($pekerjaan, '', $jenis->value);
    }

    private function queue(JenisQueue $jenis): RedisQueue
    {
        $queue = Queue::connection($jenis->koneksi());
        $this->assertInstanceOf(RedisQueue::class, $queue);

        return $queue;
    }

    /** Sama dengan supervisor Horizon untuk jenis ini: satu queue, batas waktunya sendiri. */
    private function jalankanPekerja(JenisQueue $jenis): InvokedProcess
    {
        $konfig = config('horizon.defaults.queue-'.$jenis->value);

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
