<?php

namespace App\Modules\MasterData\Domain;

/** Fase 1: belum ada penempatan pasien, jadi TT tidak pernah terisi atau dipesan. */
final class PenempatanBelumAda implements PemeriksaPenempatan
{
    public function terisiAtauDipesan(string $tempatTidurId, string $tanggal): bool
    {
        return false;
    }
}
