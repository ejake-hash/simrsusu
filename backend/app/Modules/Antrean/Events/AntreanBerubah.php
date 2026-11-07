<?php

namespace App\Modules\Antrean\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Status antrean berubah (tiket 2.8). Pelanggan menyusul: task ID Antrean Online BPJS (Tahap B). Tanpa data pasien. */
final class AntreanBerubah implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $antreanId,
        public readonly string $status,
        public readonly string $terjadiPada,
    ) {}
}
