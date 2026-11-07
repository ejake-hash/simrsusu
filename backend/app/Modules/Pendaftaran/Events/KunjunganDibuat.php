<?php

namespace App\Modules\Pendaftaran\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Kunjungan poli terdaftar (FR-00-04; tiket 2.6). Hanya id dan kode, tanpa data identitas pasien. Dikirim di dalam
 * transaksi pendaftaran: antrean poli (2.8) dibuat atomik bersama kunjungannya. Pelanggan yang butuh data sudah
 * ter-commit (mis. kirim ke luar) memasang ShouldHandleEventsAfterCommit di listener-nya.
 */
final class KunjunganDibuat
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
