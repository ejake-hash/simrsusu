<?php

namespace App\Modules\RawatInap\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pasien tiba di bangsal dan menempati TT (tiket 2.29; PRD 7.00.3 kejadian "pasien tiba di bangsal", FR-00-04). Dikirim
 * di dalam transaksi penempatan, jadi pelanggan berhasil atau gagal bersamanya. Hanya id, tanpa data pasien.
 */
final class PasienDitempatkan
{
    use Dispatchable;

    public function __construct(
        public readonly string $penempatanId,
        public readonly string $kunjunganId,
        public readonly string $pasienId,
        public readonly string $episodeId,
        public readonly string $tempatTidurId,
        public readonly string $permintaanId,
    ) {}
}
