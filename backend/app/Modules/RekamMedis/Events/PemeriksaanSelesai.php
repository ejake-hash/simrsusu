<?php

namespace App\Modules\RekamMedis\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Pemeriksaan dokter selesai, kunjungan `selesai` (tiket 2.15; task ID 5, 7.02.1): jam server untuk task ID (2.18). Diterbitkan di dalam transaksinya. */
final class PemeriksaanSelesai
{
    use Dispatchable;

    public function __construct(public readonly string $kunjunganId, public readonly string $olehId, public readonly string $terjadiPada) {}
}
