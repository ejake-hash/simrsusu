<?php

namespace App\Modules\RekamMedis\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Resep disahkan (tiket 2.17, FR-02-10): pendengar Antrean membuat atau memakai antrean farmasi pasien hari itu; task 5
 * farmasi (7.02.1) dicatat 2.18. Diterbitkan di dalam transaksinya, sinkron.
 */
final class ResepDisahkan
{
    use Dispatchable;

    public function __construct(
        public readonly string $resepId,
        public readonly string $kunjunganId,
        public readonly string $pasienId,
        public readonly string $tanggal,
        public readonly string $olehId,
        public readonly string $terjadiPada,
    ) {}
}
