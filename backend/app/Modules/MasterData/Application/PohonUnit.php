<?php

namespace App\Modules\MasterData\Application;

use App\Modules\Akses\Application\DataUnit;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Illuminate\Support\Facades\DB;

/**
 * Pohon unit organisasi untuk lapis 3 (unit induk mencakup turunannya) dan tampilan. Kueri rekursif di
 * PostgreSQL; pohon RS paling banyak ratusan node, jadi cukup tanpa cache. Perubahan struktur dikunci
 * (KelolaUnit::kunciPohon) agar tidak terbentuk siklus; kueri tetap tahan siklus (CYCLE) sebagai pagar kedua.
 */
final class PohonUnit implements DataUnit
{
    public function leluhur(string $unitId): array
    {
        return array_column(DB::select(
            'WITH RECURSIVE naik AS (
                SELECT id, induk_id, 0 AS jarak FROM inti.unit_organisasi WHERE id = ?
                UNION ALL
                SELECT u.id, u.induk_id, naik.jarak + 1 FROM inti.unit_organisasi u JOIN naik ON u.id = naik.induk_id
             ) CYCLE id SET siklus USING jejak
             SELECT id FROM naik WHERE NOT siklus ORDER BY jarak',
            [$unitId],
        ), 'id');
    }

    /**
     * Unit itu sendiri beserta semua turunannya.
     *
     * @param  list<string>  $unitId
     * @return list<string>
     */
    public function subpohon(array $unitId): array
    {
        if ($unitId === []) {
            return [];
        }

        return array_column(DB::select(
            'WITH RECURSIVE turun AS (
                SELECT id FROM inti.unit_organisasi WHERE id = ANY(?::uuid[])
                UNION
                SELECT u.id FROM inti.unit_organisasi u JOIN turun ON u.induk_id = turun.id
             ) SELECT id FROM turun',
            ['{'.implode(',', $unitId).'}'],
        ), 'id');
    }

    /**
     * Jalur induk (akar → induk langsung) untuk banyak unit sekaligus, tanpa unit itu sendiri.
     *
     * @param  list<string>  $unitId
     * @return array<string, list<array{id: string, kode: string, nama: string}>>
     */
    public function jalur(array $unitId): array
    {
        $hasil = array_fill_keys($unitId, []);
        if ($unitId === []) {
            return $hasil;
        }

        $baris = DB::select(
            'WITH RECURSIVE naik AS (
                SELECT u.id AS asal, u.induk_id AS id, 1 AS jarak FROM inti.unit_organisasi u
                 WHERE u.id = ANY(?::uuid[]) AND u.induk_id IS NOT NULL
                UNION ALL
                SELECT naik.asal, u.induk_id, naik.jarak + 1 FROM inti.unit_organisasi u JOIN naik ON u.id = naik.id
                 WHERE u.induk_id IS NOT NULL
             ) CYCLE id SET siklus USING jejak
             SELECT naik.asal, u.id, u.kode, u.nama FROM naik JOIN inti.unit_organisasi u ON u.id = naik.id
             WHERE NOT naik.siklus ORDER BY naik.asal, naik.jarak DESC',
            ['{'.implode(',', $unitId).'}'],
        );
        foreach ($baris as $b) {
            $hasil[$b->asal][] = ['id' => $b->id, 'kode' => $b->kode, 'nama' => $b->nama];
        }

        return $hasil;
    }

    /**
     * Akar subpohon dari sekumpulan unit: unit yang tidak punya leluhur di kumpulan itu.
     *
     * @param  list<string>  $unitId
     * @return list<string>
     */
    public function akar(array $unitId): array
    {
        $kumpulan = array_flip($unitId);

        return array_values(array_filter($unitId, fn (string $id) => array_filter(
            array_slice($this->leluhur($id), 1), fn (string $l) => isset($kumpulan[$l]),
        ) === []));
    }

    public function aktif(string $unitId): bool
    {
        return UnitOrganisasi::query()->whereKey($unitId)->where('status', 'aktif')->exists();
    }

    public function label(array $unitId): array
    {
        return $unitId === [] ? [] : UnitOrganisasi::query()->whereIn('id', $unitId)->get(['id', 'kode', 'nama'])
            ->mapWithKeys(fn (UnitOrganisasi $u) => [$u->id => "{$u->kode} · {$u->nama}"])->all();
    }
}
