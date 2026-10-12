<?php

namespace App\Modules\Akses\Domain;

use App\Modules\Akses\Models\Pengguna;

/**
 * Apakah akun sedang merawat pasien itu (lapis 4, PRD 9.2.2: DPJP/perawat yang ditugaskan). Diisi modul pelayanan
 * mulai fase 2; sampai saat itu jawabannya selalu "tidak merawat" (Infrastructure\TanpaRelasiPerawatan).
 */
interface RelasiPerawatan
{
    public function merawat(Pengguna $pengguna, ?string $pasienId): bool;
}
