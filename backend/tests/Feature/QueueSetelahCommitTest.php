<?php

namespace Tests\Feature;

use App\Core\Queue\JenisQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\PekerjaanUjiQueue;
use Tests\TestCase;

/**
 * Pekerjaan yang dikirim di dalam transaksi baru masuk queue setelah commit (after_commit di
 * config/queue.php). Tanpa itu pekerja bisa mengambil pekerjaan untuk data yang belum tersimpan,
 * atau yang tidak pernah tersimpan karena transaksinya batal.
 */
class QueueSetelahCommitTest extends TestCase
{
    /** @var list<array{JenisQueue, string}> queue uji yang perlu dibersihkan */
    private array $dipakai = [];

    protected function tearDown(): void
    {
        foreach ($this->dipakai as [$jenis, $nama]) {
            $this->queue($jenis)->clear($nama);
            Redis::connection()->del("$nama:mulai", "$nama:selesai");
        }

        parent::tearDown();
    }

    public function test_pekerjaan_dari_transaksi_batal_tidak_pernah_dijalankan(): void
    {
        foreach (JenisQueue::cases() as $jenis) {
            $nama = $this->queueUji($jenis);

            try {
                DB::transaction(function () use ($jenis, $nama) {
                    $this->kirim($jenis, $nama);
                    throw new RuntimeException('transaksi batal');
                });
            } catch (RuntimeException) {
            }

            $this->assertSame(0, $this->queue($jenis)->size($nama),
                "Pekerjaan {$jenis->value} dari transaksi batal masuk queue.");
            $this->jalankanSatuPekerjaan($jenis, $nama);
            $this->assertFalse($this->ada("$nama:selesai"), "Pekerjaan {$jenis->value} dari transaksi batal dijalankan.");
        }
    }

    public function test_pekerjaan_dari_transaksi_yang_commit_dijalankan_setelah_commit(): void
    {
        foreach (JenisQueue::cases() as $jenis) {
            $nama = $this->queueUji($jenis);

            DB::transaction(function () use ($jenis, $nama) {
                $this->kirim($jenis, $nama);
                $this->assertSame(0, $this->queue($jenis)->size($nama),
                    "Pekerjaan {$jenis->value} masuk queue sebelum commit.");
            });

            $this->assertSame(1, $this->queue($jenis)->size($nama));
            $this->jalankanSatuPekerjaan($jenis, $nama);
            $this->assertTrue($this->ada("$nama:selesai"), "Pekerjaan {$jenis->value} tidak dijalankan setelah commit.");
        }
    }

    private function queueUji(JenisQueue $jenis): string
    {
        $nama = 'uji-commit:'.Str::uuid().':'.$jenis->value;
        $this->dipakai[] = [$jenis, $nama];

        return $nama;
    }

    private function queue(JenisQueue $jenis): RedisQueue
    {
        $queue = Queue::connection($jenis->koneksi());
        $this->assertInstanceOf(RedisQueue::class, $queue);

        return $queue;
    }

    /** Sama dengan pemakaian di aplikasi: koneksi dan queue dari jenisnya, bukan push langsung. */
    private function kirim(JenisQueue $jenis, string $nama): void
    {
        dispatch(new PekerjaanUjiQueue($nama))->onConnection($jenis->koneksi())->onQueue($nama);
    }

    private function jalankanSatuPekerjaan(JenisQueue $jenis, string $nama): void
    {
        Artisan::call('queue:work', [
            'connection' => $jenis->koneksi(), '--queue' => $nama, '--once' => true, '--sleep' => 0, '--tries' => 1,
        ]);
    }

    private function ada(string $kunci): bool
    {
        return (bool) Redis::connection()->exists($kunci);
    }
}
