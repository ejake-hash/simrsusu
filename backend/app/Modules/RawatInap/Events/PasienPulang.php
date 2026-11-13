<?php

namespace App\Modules\RawatInap\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pasien rawat inap keluar (tiket 2.40; FR-05-05): pulang, APS, rujuk keluar, atau meninggal. Dikirim di dalam transaksi
 * pemulangan, diteruskan ke pendengar sesudah commit (tagihan dan SATUSEHAT menyusul, Tahap D). Hanya id dan kode tujuan,
 * tanpa data pasien maupun alasan.
 */
final class PasienPulang implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $perpindahanId,
        public readonly string $kunjunganId,
        public readonly string $pasienId,
        public readonly string $episodeId,
        public readonly string $tujuan,
        public readonly string $tempatTidurId,
        public readonly string $terjadiPada,
    ) {}
}
