<?php

namespace App\Modules\MasterData\Application;

use Illuminate\Support\Facades\DB;

/**
 * Pohon lokasi fisik (tiket 1.8b): turunan, jalur induk, dan penghitungan ulang kode_lengkap. Perubahan struktur
 * berurutan lewat kunci pohon; kueri tetap tahan siklus (CYCLE) sebagai pagar kedua.
 */
final class PohonLokasi
{
    /** Satu kunci untuk seluruh pohon: pindah induk, ganti kode, dan tambah node tidak bisa saling mendahului. */
    public function kunci(): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext('inti.lokasi.pohon'))");
    }

    /**
     * Lokasi itu sendiri beserta semua turunannya.
     *
     * @return list<string>
     */
    public function subpohon(string $id): array
    {
        return array_column(DB::select(
            'WITH RECURSIVE turun AS (
                SELECT id FROM inti.lokasi WHERE id = ?
                UNION
                SELECT l.id FROM inti.lokasi l JOIN turun ON l.induk_id = turun.id
             ) SELECT id FROM turun',
            [$id],
        ), 'id');
    }

    /**
     * Jalur induk (akar → induk langsung) untuk banyak lokasi sekaligus, tanpa lokasi itu sendiri.
     *
     * @param  list<string>  $id
     * @return array<string, list<array{id: string, kode_lengkap: string, nama: string}>>
     */
    public function jalur(array $id): array
    {
        $hasil = array_fill_keys($id, []);
        if ($id === []) {
            return $hasil;
        }
        $baris = DB::select(
            'WITH RECURSIVE naik AS (
                SELECT l.id AS asal, l.induk_id AS id, 1 AS jarak FROM inti.lokasi l
                 WHERE l.id = ANY(?::uuid[]) AND l.induk_id IS NOT NULL
                UNION ALL
                SELECT naik.asal, l.induk_id, naik.jarak + 1 FROM inti.lokasi l JOIN naik ON l.id = naik.id
                 WHERE l.induk_id IS NOT NULL
             ) CYCLE id SET siklus USING jejak
             SELECT naik.asal, l.id, l.kode_lengkap, l.nama FROM naik JOIN inti.lokasi l ON l.id = naik.id
             WHERE NOT naik.siklus ORDER BY naik.asal, naik.jarak DESC',
            ['{'.implode(',', $id).'}'],
        );
        foreach ($baris as $b) {
            $hasil[$b->asal][] = ['id' => $b->id, 'kode_lengkap' => $b->kode_lengkap, 'nama' => $b->nama];
        }

        return $hasil;
    }

    /** Hitung ulang kode_lengkap seluruh turunan dari kode_lengkap lokasi itu (sesudah pindah induk atau ganti kode). */
    public function hitungUlangTurunan(string $id): void
    {
        DB::update(
            "WITH RECURSIVE turun AS (
                SELECT id, kode_lengkap FROM inti.lokasi WHERE id = ?
                UNION ALL
                SELECT l.id, turun.kode_lengkap || '-' || l.kode FROM inti.lokasi l JOIN turun ON l.induk_id = turun.id
             ) CYCLE id SET siklus USING jejak
             UPDATE inti.lokasi l SET kode_lengkap = turun.kode_lengkap FROM turun
             WHERE l.id = turun.id AND l.id <> ? AND NOT turun.siklus AND l.kode_lengkap <> turun.kode_lengkap",
            [$id, $id],
        );
    }

    public static function kodeLengkap(?string $kodeLengkapInduk, string $kode): string
    {
        return $kodeLengkapInduk === null ? $kode : $kodeLengkapInduk.'-'.$kode;
    }
}
