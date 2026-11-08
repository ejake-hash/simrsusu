<?php

namespace App\Modules\Antrean\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Status antrean berubah (tiket 2.8). Pelanggan: task ID antrean (tiket 2.18, CatatTaskAntrean). Tanpa data pasien. */
final class AntreanBerubah implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $antreanId,
        public readonly string $status,
        public readonly string $terjadiPada,
    ) {}
}
