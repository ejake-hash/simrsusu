<?php

namespace App\Modules\Pendaftaran\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Kunjungan poli terdaftar (FR-00-04; tiket 2.6). Pelanggan: SEP (2.7), antrean (2.8). Hanya id dan kode, tanpa data
 * identitas pasien. Dikirim sesudah commit.
 */
final class KunjunganDibuat implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $kunjunganId,
        public readonly string $pasienId,
        public readonly string $episodeId,
        public readonly string $poliId,
        public readonly string $pegawaiId,
        public readonly string $tanggal,
        public readonly string $caraBayar,
    ) {}
}
