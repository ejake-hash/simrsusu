<?php

namespace App\Modules\MasterData\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Jumlah TT sebuah bangsal berubah mulai sebuah tanggal (TT ditambah, ditutup, kelas/ekstra diubah). Dipakai sinkron
 * Aplicares/SIRANAP di tiket 1.9. Dikirim sesudah commit.
 */
final class KapasitasTempatTidurBerubah implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $bangsalId,
        public readonly string $mulaiTanggal,
    ) {}
}
