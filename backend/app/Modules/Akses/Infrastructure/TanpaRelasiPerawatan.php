<?php

namespace App\Modules\Akses\Infrastructure;

use App\Modules\Akses\Domain\RelasiPerawatan;
use App\Modules\Akses\Models\Pengguna;

/** Fase 1: belum ada modul pelayanan, jadi tidak ada akun yang merawat pasien (tiket 1.5). */
final class TanpaRelasiPerawatan implements RelasiPerawatan
{
    public function merawat(Pengguna $pengguna, ?string $pasienId): bool
    {
        return false;
    }
}
