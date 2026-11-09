<?php

namespace App\Modules\Pendaftaran\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Perintah disposisi IGD tercatat (FR-00-04, FR-03-09; tiket 2.26), untuk modul lain yang berlangganan (papan IGD,
 * tagihan, SATUSEHAT). Hanya id dan kode, tanpa identitas, alasan, maupun serah terima. Dikirim di dalam transaksi
 * perintah. `status`: selesai | menunggu_kamar | menunggu_kamar_operasi.
 */
final class DisposisiDicatat
{
    use Dispatchable;

    public function __construct(
        public readonly string $perpindahanId,
        public readonly string $kunjunganId,
        public readonly string $pasienId,
        public readonly string $episodeId,
        public readonly string $posTujuan,
        public readonly string $status,
        public readonly string $diputuskanOleh,
        public readonly string $terjadiPada,
    ) {}
}
