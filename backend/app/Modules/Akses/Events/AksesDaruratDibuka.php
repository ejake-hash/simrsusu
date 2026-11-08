<?php

namespace App\Modules\Akses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Akses darurat baru (tiket 2.21). Didengar sinkron oleh modul Notifikasi: tugas tinjauan pasca-akses. Tanpa data pasien. */
final class AksesDaruratDibuka
{
    use Dispatchable;

    public function __construct(public readonly string $aksesDaruratId) {}
}
