<?php

namespace App\Modules\MasterData\Domain;

/**
 * Apakah TT terisi atau dipesan pada/sesudah sebuah tanggal (FR-08-17). Implementasinya milik modul Rawat Inap
 * (RawatInap\Application\PemeriksaPenempatanTempatTidur, tiket 2.29).
 */
interface PemeriksaPenempatan
{
    public function terisiAtauDipesan(string $tempatTidurId, string $tanggal): bool;
}
