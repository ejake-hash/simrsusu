<?php

namespace App\Modules\Akses\Domain;

use App\Modules\Akses\Models\Pengguna;

/**
 * Apakah akun sedang merawat pasien itu (lapis 4, PRD 9.2.2: DPJP/perawat yang ditugaskan). Sumbernya
 * akses.relasi_perawatan (Infrastructure\RelasiPerawatanTercatat, tiket 2.6).
 */
interface RelasiPerawatan
{
    public function merawat(Pengguna $pengguna, ?string $pasienId): bool;
}
