<?php

namespace App\Modules\Akses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Akun baru saja terkunci karena salah sandi berulang (issue #11). Didengar sinkron (di dalam transaksi pemanggil) oleh modul Notifikasi (tiket 1.7). */
final class AkunTerkunci
{
    use Dispatchable;

    public function __construct(
        public readonly string $penggunaId,
        public readonly string $terkunciSampai,
    ) {}
}
