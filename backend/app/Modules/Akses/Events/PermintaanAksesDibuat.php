<?php

namespace App\Modules\Akses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Permintaan akses baru dari akun yang aksesnya ditolak (layar Akses ditolak). Didengar sinkron (di dalam transaksi pemanggil) oleh modul Notifikasi (tiket 1.7). */
final class PermintaanAksesDibuat
{
    use Dispatchable;

    public function __construct(
        public readonly string $permintaanId,
        public readonly string $nomor,
    ) {}
}
