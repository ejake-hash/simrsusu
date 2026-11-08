<?php

namespace App\Modules\RekamMedis\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Order penunjang dibuat (tiket 2.16, 7.00.5): pendengarnya penunjang lab/radiologi (Tahap C). Diterbitkan di dalam transaksinya. */
final class PermintaanPemeriksaanDibuat
{
    use Dispatchable;

    public function __construct(public readonly string $permintaanId, public readonly string $olehId, public readonly string $terjadiPada) {}
}
