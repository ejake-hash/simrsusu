<?php

namespace App\Modules\Akses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Tinjauan pasca-akses tersimpan (tiket 2.21). Didengar sinkron oleh modul Notifikasi: tugas tinjauan selesai. */
final class AksesDaruratDitinjau
{
    use Dispatchable;

    public function __construct(
        public readonly string $aksesDaruratId,
        public readonly string $hasil,
    ) {}
}
