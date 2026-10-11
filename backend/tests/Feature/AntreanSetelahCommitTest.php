<?php

namespace Tests\Feature;

use App\Core\Antrean\JenisAntrean;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\PekerjaanUjiAntrean;
use Tests\TestCase;

/**
 * Pekerjaan yang dikirim di dalam transaksi baru masuk antrean setelah commit (after_commit di
 * config/queue.php). Tanpa itu pekerja bisa mengambil pekerjaan untuk data yang belum tersimpan,
 * atau yang tidak pernah tersimpan karena transaksinya batal.
 */
class AntreanSetelahCommitTest extends TestCase
{
    /** @var list<array{JenisAntrean, string}> antrean uji yang perlu dibersihkan */
    private array $dipakai = [];

    protected function tearDown(): void
    {
        foreach ($this->dipakai as [$jenis, $nama]) {
            $this->antrean($jenis)->clear($nama);
            Redis::connection()->del("$nama:mulai", "$nama:selesai");
        }

        parent::tearDown();
    }

    public function test_pekerjaan_dari_transaksi_batal_tidak_pernah_dijalankan(): void
    {
        foreach (JenisAntrean::cases() as $jenis) {
            $nama = $this->antreanUji($jenis);

            try {
                DB::transaction(function () use ($jenis, $nama) {
                    $this->kirim($jenis, $nama);
                    throw new RuntimeException('transaksi batal');
                });
            } catch (RuntimeException) {
            }

            $this->assertSame(0, $this->antrean($jenis)->size($nama),
                "Pekerjaan {$jenis->value} dari transaksi batal masuk antrean.");
            $this->jalankanSatuPekerjaan($jenis, $nama);
            $this->assertFalse($this->ada("$nama:selesai"), "Pekerjaan {$jenis->value} dari transaksi batal dijalankan.");
        }
    }

    public function test_pekerjaan_dari_transaksi_yang_commit_dijalankan_setelah_commit(): void
    {
        foreach (JenisAntrean::cases() as $jenis) {
            $nama = $this->antreanUji($jenis);

            DB::transaction(function () use ($jenis, $nama) {
                $this->kirim($jenis, $nama);
                $this->assertSame(0, $this->antrean($jenis)->size($nama),
                    "Pekerjaan {$jenis->value} masuk antrean sebelum commit.");
            });

            $this->assertSame(1, $this->antrean($jenis)->size($nama));
            $this->jalankanSatuPekerjaan($jenis, $nama);
            $this->assertTrue($this->ada("$nama:selesai"), "Pekerjaan {$jenis->value} tidak dijalankan setelah commit.");
        }
    }

    private function antreanUji(JenisAntrean $jenis): string
    {
        $nama = 'uji-commit:'.Str::uuid().':'.$jenis->value;
        $this->dipakai[] = [$jenis, $nama];

        return $nama;
    }

    private function antrean(JenisAntrean $jenis): RedisQueue
    {
        $antrean = Queue::connection($jenis->koneksi());
        $this->assertInstanceOf(RedisQueue::class, $antrean);

        return $antrean;
    }

    /** Sama dengan pemakaian di aplikasi: koneksi dan antrean dari jenisnya, bukan push langsung. */
    private function kirim(JenisAntrean $jenis, string $nama): void
    {
        dispatch(new PekerjaanUjiAntrean($nama))->onConnection($jenis->koneksi())->onQueue($nama);
    }

    private function jalankanSatuPekerjaan(JenisAntrean $jenis, string $nama): void
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
