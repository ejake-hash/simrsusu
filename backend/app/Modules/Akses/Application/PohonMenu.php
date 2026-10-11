<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pohon menu lengkap beserta izin aksi per node (kontrak pohonMenu), untuk matriks peran dan pemilih
 * pengecualian per akun. Memuat semua node aktif, termasuk modul yang belum tersedia, agar peran bisa
 * disiapkan sebelum modulnya dinyalakan.
 */
final class PohonMenu
{
    /** @return array{grup: list<array{kode: string, label: string|null, menu: list<array<string, mixed>>}>} */
    public function semua(): array
    {
        $modulAktif = DB::table('inti.modul_aktif')->whereNull('unit_organisasi_id')->pluck('modul_no')->map(fn ($n) => (int) $n)->flip();
        $izin = Izin::query()->orderBy('kode')->get(['kode', 'menu_id', 'jenis_aksi', 'sensitif'])->groupBy('menu_id');
        $anak = Menu::query()->where('aktif', true)->orderBy('urutan')->orderBy('kode')->get()->toBase()->groupBy(fn (Menu $m) => (string) $m->induk_id);

        $node = function (Menu $m) use (&$node, $anak, $izin, $modulAktif): array {
            return [
                'id' => $m->id,
                'kode' => $m->kode,
                'label' => (string) $m->label,
                'tingkat' => $m->tingkat,
                'ikon' => $m->ikon,
                'fase' => $m->fase,
                'tersedia' => $m->tersedia,
                'modul_aktif' => $m->modul_no !== null && isset($modulAktif[$m->modul_no]),
                'ikut_induk' => $m->ikut_induk,
                'izin' => ($izin[$m->id] ?? collect())->map(fn (Izin $i) => [
                    'kode' => $i->kode, 'jenis_aksi' => $i->jenis_aksi, 'sensitif' => $i->sensitif,
                ])->values()->all(),
                'anak' => $this->anak($anak, $m->id)->map($node)->values()->all(),
            ];
        };

        return ['grup' => $this->anak($anak, '')->map(fn (Menu $g) => [
            'kode' => $g->kode,
            'label' => $g->label,
            'menu' => $this->anak($anak, $g->id)->map($node)->values()->all(),
        ])->values()->all()];
    }

    /**
     * Label jalur sebuah node, mis. "Kasir → Tagihan pasien" (tanpa grup).
     *
     * @param  Collection<string, Menu>  $menu  semua menu, berkunci id
     */
    public static function jalurLabel(Collection $menu, string $id): string
    {
        $label = [];
        for ($m = $menu[$id] ?? null; $m !== null && $m->tingkat !== 'grup'; $m = $menu[$m->induk_id] ?? null) {
            array_unshift($label, (string) $m->label);
        }

        return implode(' → ', $label);
    }

    /** Label aksi untuk layar, mis. lihat_rupiah → "Lihat rupiah". */
    public static function labelAksi(string $jenisAksi): string
    {
        return ucfirst(str_replace('_', ' ', $jenisAksi));
    }

    /**
     * @param  Collection<array-key, Collection<int, Menu>>  $anak
     * @return Collection<int, Menu>
     */
    private function anak(Collection $anak, string $indukId): Collection
    {
        return $anak->get($indukId) ?? collect();
    }
}
