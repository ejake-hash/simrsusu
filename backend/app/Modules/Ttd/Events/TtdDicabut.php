<?php

namespace App\Modules\Ttd\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** TTE dicabut karena reset disetujui (tiket 1.6). Didengar sinkron oleh modul Notifikasi: notifikasi ke pemilik. */
final class TtdDicabut
{
    use Dispatchable;

    public function __construct(
        public readonly string $penggunaId,
        public readonly string $nomorPersetujuan,
    ) {}
}
