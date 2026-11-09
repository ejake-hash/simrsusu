<?php

namespace App\Modules\Pendaftaran\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Kunjungan IGD terdaftar (FR-00-04; tiket 2.23). Terpisah dari KunjunganDibuat agar pelanggan antrean poli dan task
 * antrean BPJS tidak menerimanya. Hanya id dan kode, tanpa identitas maupun keluhan. Dikirim di dalam transaksi.
 */
final class KunjunganIgdDibuat
{
    use Dispatchable;

    public function __construct(
        public readonly string $kunjunganId,
        public readonly string $pasienId,
        public readonly string $episodeId,
        public readonly string $tibaPada,
        public readonly string $caraBayar,
    ) {}
}
