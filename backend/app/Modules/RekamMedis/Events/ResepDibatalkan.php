<?php

namespace App\Modules\RekamMedis\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Resep sah dibatalkan (tiket 2.17): antrean farmasinya ikut batal bila tidak ada resep sah lain dan belum dilayani. */
final class ResepDibatalkan
{
    use Dispatchable;

    public function __construct(public readonly string $antreanId, public readonly string $olehId, public readonly string $alasan) {}
}
