<?php

namespace App\Modules\Sdm\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Catatan izin praktik dibatalkan. Didengar sinkron (di dalam transaksi pemanggil) oleh modul Notifikasi (tiket 1.7). */
final class IzinPraktikDibatalkan
{
    use Dispatchable;

    public function __construct(
        public readonly string $izinPraktikId,
    ) {}
}
