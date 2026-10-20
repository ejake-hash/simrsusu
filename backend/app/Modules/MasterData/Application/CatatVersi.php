<?php

namespace App\Modules\MasterData\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Versi data induk ruang/kamar/TT (tiket 1.8c, FR-54-02): isi sebelum dan sesudah setiap perubahan, lewat usulan maupun
 * langsung. Dipanggil di dalam transaksi perubahan (sesudah CAS revisi, jadi versi objek yang sama tidak bertabrakan).
 * Bila perubahan sedang diterapkan dari usulan, `usulanId` diisi MesinUsulan selama penerapan.
 */
final class CatatVersi
{
    private ?string $usulanId = null;

    /**
     * @template T
     *
     * @param  callable(): T  $kerja
     * @return T
     */
    public function dariUsulan(string $usulanId, callable $kerja): mixed
    {
        $this->usulanId = $usulanId;
        try {
            return $kerja();
        } finally {
            $this->usulanId = null;
        }
    }

    /** Usulan yang sedang diterapkan (null di luar penerapan usulan); dipakai tabel versi jenis data lain (tarif, 2.2). */
    public function usulanAktif(): ?string
    {
        return $this->usulanId;
    }

    /**
     * Versi pertama banyak objek baru sekaligus (impor CSV 1.8b).
     *
     * @param  list<array{0: string, 1: string, 2: array<string, mixed>}>  $objek  [objek_jenis, objek_id, sesudah]
     */
    public function catatBaru(array $objek, string $berlakuDari, string $dasar, ?string $oleh): void
    {
        $sekarang = now()->format('Y-m-d H:i:s.uP');
        $baris = array_map(fn (array $o) => [
            'id' => (string) Str::uuid7(), 'objek_jenis' => $o[0], 'objek_id' => $o[1], 'versi' => 1, 'sebelum' => null,
            'sesudah' => json_encode($o[2]), 'berlaku_dari' => $berlakuDari, 'usulan_id' => $this->usulanId, 'dasar' => $dasar,
            'dibuat_oleh' => $oleh, 'dibuat_pada' => $sekarang,
        ], $objek);
        foreach (array_chunk($baris, 500) as $potong) {
            DB::table('inti.data_induk_versi')->insert($potong);
        }
    }

    /**
     * @param  array<string, mixed>|null  $sebelum  null = objek dibuat
     * @param  array<string, mixed>  $sesudah
     */
    public function catat(string $objekJenis, string $objekId, ?array $sebelum, array $sesudah, string $berlakuDari, string $dasar, ?string $oleh): void
    {
        $versi = (int) DB::table('inti.data_induk_versi')->where('objek_jenis', $objekJenis)->where('objek_id', $objekId)->max('versi') + 1;
        DB::table('inti.data_induk_versi')->insert([
            'id' => (string) Str::uuid7(), 'objek_jenis' => $objekJenis, 'objek_id' => $objekId, 'versi' => $versi,
            'sebelum' => $sebelum === null ? null : json_encode($sebelum), 'sesudah' => json_encode($sesudah),
            'berlaku_dari' => $berlakuDari, 'usulan_id' => $this->usulanId, 'dasar' => $dasar, 'dibuat_oleh' => $oleh,
            'dibuat_pada' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
