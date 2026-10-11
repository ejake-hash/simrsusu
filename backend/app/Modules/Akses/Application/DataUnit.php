<?php

namespace App\Modules\Akses\Application;

/**
 * Data unit organisasi yang dibutuhkan Akses (lapis 3 cakupan `unit`, label cakupan). Diimplementasikan modul
 * MasterData; Akses tidak membaca tabel inti.unit_organisasi langsung (PRD 10.3).
 */
interface DataUnit
{
    /** Unit itu sendiri beserta semua induknya sampai akar; kosong bila unit tidak ada. @return list<string> */
    public function leluhur(string $unitId): array;

    /** Unit ada dan berstatus aktif. */
    public function aktif(string $unitId): bool;

    /**
     * Label "KODE · Nama" per unit untuk tampilan; unit yang tidak ada tidak dimuat.
     *
     * @param  list<string>  $unitId
     * @return array<string, string>
     */
    public function label(array $unitId): array;
}
