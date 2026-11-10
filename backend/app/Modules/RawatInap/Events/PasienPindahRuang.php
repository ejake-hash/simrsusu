<?php

namespace App\Modules\RawatInap\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pasien rawat inap pindah ruang/kelas (tiket 2.29; FR-08-06, FR-00-03). Dikirim di dalam transaksi pindah ruang. Hanya
 * id, tanpa data pasien maupun isi serah terima.
 */
final class PasienPindahRuang
{
    use Dispatchable;

    public function __construct(
        public readonly string $perpindahanId,
        public readonly string $kunjunganId,
        public readonly string $pasienId,
        public readonly string $dariTempatTidurId,
        public readonly string $keTempatTidurId,
        public readonly string $penempatanId,
    ) {}
}
