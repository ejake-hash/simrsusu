<?php

namespace App\Modules\Pendaftaran\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dokter IGD memutuskan rawat inap (FR-03-09; tiket 2.26): pasien menunggu kamar dan belum ditempatkan. Permintaan
 * kamar, SPRI, dan alokasi TT dibangun tiket 2.28 yang berlangganan kejadian ini (atau membaca perintah
 * `perpindahan_pos` berstatus `menunggu_kamar`). Hanya id, tanpa identitas maupun isi klinis.
 */
final class DisposisiRawatInap
{
    use Dispatchable;

    public function __construct(
        public readonly string $perpindahanId,
        public readonly string $kunjunganId,
        public readonly string $pasienId,
        public readonly string $episodeId,
        public readonly string $diputuskanOleh,
    ) {}
}
