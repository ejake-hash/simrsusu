<?php

namespace App\Modules\Akses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Persetujuan akses menunggu keputusan; satu kejadian per baris (per peran penyetuju). Didengar sinkron (di dalam transaksi pemanggil) oleh modul Notifikasi (tiket 1.7). */
final class PersetujuanDiajukan
{
    use Dispatchable;

    public function __construct(
        public readonly string $persetujuanId,
        public readonly string $nomor,
        public readonly string $penyetujuPeranId,
    ) {}
}
