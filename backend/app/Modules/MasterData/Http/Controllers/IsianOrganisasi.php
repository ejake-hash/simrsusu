<?php

namespace App\Modules\MasterData\Http\Controllers;

/** Aturan validasi bersama data induk lokasi/ruang/TT (kontrak v0.5.0: Dasar, KodeData, KodeLokasi). */
final class IsianOrganisasi
{
    public const DASAR = ['required', 'string', 'min:3', 'max:200'];

    public const KODE_DATA = ['string', 'regex:/^[A-Z0-9][A-Z0-9._-]{0,29}$/'];

    public const KODE_LOKASI = ['string', 'regex:/^[A-Z0-9]{1,10}$/'];
}
