<?php

namespace App\Modules\Ttd\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * TTE terkunci karena PIN salah berulang (tiket 1.6). Didengar sinkron (di dalam transaksi penghitung) oleh modul
 * Notifikasi: notifikasi kritis ke pemilik dan atasan langsung (null bila belum ditetapkan).
 */
final class TtdTerkunci
{
    use Dispatchable;

    public function __construct(
        public readonly string $penggunaId,
        public readonly ?string $atasanId,
        public readonly string $terkunciSampai,
    ) {}
}
