<?php

namespace App\Modules\Pendaftaran\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Kunjungan dibatalkan (manual atau otomatis jam tutup; tiket 2.6). Dikirim di dalam transaksi pembatalan, jadi pelanggan
 * (antrean 2.8 ikut batal) berhasil atau gagal bersama pembatalannya. Hanya id, tanpa data pasien.
 */
final class KunjunganDibatalkan
{
    use Dispatchable;

    /** @param  string|null  $olehId  null = sistem */
    public function __construct(
        public readonly string $kunjunganId,
        public readonly ?string $olehId,
    ) {}
}
