<?php

namespace App\Modules\Akses\Infrastructure;

use App\Modules\Akses\Application\KebijakanMfa;
use App\Modules\Akses\Application\KelolaPerangkat;
use App\Modules\Akses\Application\PendaftaranPerangkat;
use Illuminate\Http\Request;

/**
 * Registri perangkat = perangkat tepercaya (tiket 1.13, menggantikan PerangkatBelumTerdaftar; ADR 0002). Langkah masuk
 * ini memakai atau baru mempercayai perangkat dari jaringan RS → batas diam 60 menit; selain itu 15 menit.
 */
final class PerangkatTepercayaSesi implements PendaftaranPerangkat
{
    public function __construct(private readonly KebijakanMfa $kebijakanMfa) {}

    public function terdaftar(Request $request): bool
    {
        return $request->attributes->get(KelolaPerangkat::ATRIBUT) !== null && $this->kebijakanMfa->dariJaringanRs($request->ip());
    }
}
