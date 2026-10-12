<?php

namespace App\Modules\Akses\Domain;

/**
 * Kode tindakan yang akan dilakukan, untuk lapis 5 RKK (tiket 1.5): Gate::allows($izin, [$cakupan, new TindakanKlinis($kode)]).
 * Kode dari referensi resmi yang dipakai RS di RKK; tidak ada daftar bawaan.
 */
final readonly class TindakanKlinis
{
    public function __construct(public string $kode) {}
}
