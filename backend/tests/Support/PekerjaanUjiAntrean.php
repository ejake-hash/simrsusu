<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Redis;

/**
 * Pekerjaan uji untuk AntreanPerJenisTest. Dijalankan proses pekerja terpisah, jadi penanda
 * ditulis langsung ke Valkey (bukan cache tes yang berupa array). `$tahan` meniru sistem luar
 * yang macet: menunggu sampai kunci lepas diset (maksimal 20 detik).
 */
class PekerjaanUjiAntrean implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $penanda, public bool $tahan = false) {}

    public function handle(): void
    {
        $redis = Redis::connection();
        $redis->setex("{$this->penanda}:mulai", 60, '1');

        $batas = microtime(true) + 20;
        while ($this->tahan && ! $redis->exists("{$this->penanda}:lepas") && microtime(true) < $batas) {
            usleep(100_000);
        }

        $redis->setex("{$this->penanda}:selesai", 60, '1');
    }
}
