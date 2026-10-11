<?php

namespace App\Modules\MasterData\Domain;

/**
 * Apakah TT terisi atau dipesan pada/sesudah sebuah tanggal (FR-08-17). Penempatan pasien dibangun di fase 2;
 * sampai itu implementasi bawaan selalu menjawab kosong.
 */
interface PemeriksaPenempatan
{
    public function terisiAtauDipesan(string $tempatTidurId, string $tanggal): bool;
}
