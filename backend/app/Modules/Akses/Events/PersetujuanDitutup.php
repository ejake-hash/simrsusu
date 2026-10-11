<?php

namespace App\Modules\Akses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Baris persetujuan akses tidak lagi menunggu (disetujui, ditolak, kedaluwarsa, dibatalkan). Didengar sinkron (di dalam transaksi pemanggil) oleh modul Notifikasi (tiket 1.7). */
final class PersetujuanDitutup
{
    use Dispatchable;

    public function __construct(
        /** @var list<string> */
        public readonly array $persetujuanId,
        public readonly string $status,
    ) {}
}
