<?php

namespace App\Modules\Pendaftaran\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Kunjungan IGD melewati 6 jam dan dipindah ke episode `igd_lebih_6_jam` beserta aktivitasnya (FR-00-04; tiket 2.23).
 * Hanya id, tanpa identitas maupun isi klinis. Dikirim di dalam transaksi pemindahan.
 */
final class EpisodeIgdDibentuk
{
    use Dispatchable;

    public function __construct(
        public readonly string $episodeId,
        public readonly string $episodeLamaId,
        public readonly string $kunjunganId,
        public readonly string $pasienId,
    ) {}
}
