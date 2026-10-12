<?php

namespace App\Modules\Integrasi\Domain;

/**
 * Hasil satu pengiriman. `gangguan` = sistem luar/jaringan bermasalah (retry + circuit breaker); penolakan isi
 * (`berhasil` false tanpa gangguan) langsung gagal karena kirim ulang tidak akan mengubah jawabannya.
 */
final class HasilKirim
{
    private function __construct(
        public readonly bool $berhasil,
        public readonly bool $gangguan,
        public readonly ?string $pesan,
        public readonly ?string $idLuar,
    ) {}

    public static function berhasil(?string $idLuar = null): self
    {
        return new self(true, false, null, $idLuar);
    }

    public static function gangguan(string $pesan): self
    {
        return new self(false, true, $pesan, null);
    }

    public static function ditolak(string $pesan): self
    {
        return new self(false, false, $pesan, null);
    }
}
