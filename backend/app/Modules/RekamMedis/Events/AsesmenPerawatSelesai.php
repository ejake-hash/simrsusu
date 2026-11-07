<?php

namespace App\Modules\RekamMedis\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Asesmen awal perawat RJ selesai (tiket 2.13): antrean poli pindah ke tahap dokter; dipakai task ID (2.18). */
final class AsesmenPerawatSelesai
{
    use Dispatchable;

    public function __construct(public readonly string $kunjunganId, public readonly string $olehId, public readonly string $terjadiPada) {}
}
