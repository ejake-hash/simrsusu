<?php

namespace App\Modules\Integrasi\Domain;

/** Satu baris `integrasi.kotak_keluar` yang sedang diproses pekerja. */
final class Kiriman
{
    /** @param  array<string, mixed>  $muatan */
    public function __construct(
        public readonly string $id,
        public readonly SistemIntegrasi $tujuan,
        public readonly ?string $lingkungan,
        public readonly string $jenisResource,
        public readonly string $agregatJenis,
        public readonly string $agregatId,
        public readonly array $muatan,
        public readonly string $kunciIdempotensi,
        public readonly int $percobaan,
        public readonly ?string $idLuar,
    ) {}

    public static function dariBaris(object $b): self
    {
        return new self($b->id, SistemIntegrasi::from($b->tujuan), $b->lingkungan, $b->jenis_resource, $b->agregat_jenis, $b->agregat_id,
            (array) json_decode($b->muatan, true), $b->kunci_idempotensi, (int) $b->percobaan, $b->id_luar);
    }
}
