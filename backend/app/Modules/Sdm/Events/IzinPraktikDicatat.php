<?php

namespace App\Modules\Sdm\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Izin praktik baru dicatat (pengganti izin yang akan berakhir). Didengar sinkron (di dalam transaksi pemanggil) oleh modul Notifikasi (tiket 1.7). */
final class IzinPraktikDicatat
{
    use Dispatchable;

    public function __construct(
        public readonly string $izinPraktikId,
        public readonly string $pegawaiId,
        public readonly string $jenis,
    ) {}
}
