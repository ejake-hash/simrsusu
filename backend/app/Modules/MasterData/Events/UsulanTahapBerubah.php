<?php

namespace App\Modules\MasterData\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Usulan data induk berpindah tahap (tiket 1.8c). Didengar sinkron (di dalam transaksi pemanggil) oleh modul Notifikasi:
 * tugas tahap sebelumnya selesai, tugas tahap baru dibuat untuk pelaksananya.
 */
final class UsulanTahapBerubah
{
    use Dispatchable;

    public function __construct(
        public readonly string $usulanId,
        public readonly string $nomor,
        public readonly string $tahap,
        public readonly string $pengusulId,
        public readonly ?string $peranPemverifikasiId,
        public readonly ?string $peranPenyetujuId,
        public readonly ?string $catatan = null,
    ) {}
}
