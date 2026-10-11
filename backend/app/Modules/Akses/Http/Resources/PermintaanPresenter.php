<?php

namespace App\Modules\Akses\Http\Resources;

use App\Modules\Akses\Application\PohonMenu;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PermintaanAkses;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Collection;

/** Bentuk PermintaanAkses (kontrak v0.4.0); akun, pelaku, dan label sasaran dimuat sekaligus per halaman. */
final class PermintaanPresenter
{
    public function __construct(private readonly RingkasanPegawai $pegawai) {}

    /** @return array<string, mixed> */
    public function satu(PermintaanAkses $p): array
    {
        return $this->daftar(collect([$p]))[0];
    }

    /**
     * @param  Collection<int, PermintaanAkses>  $baris
     * @return list<array<string, mixed>>
     */
    public function daftar(Collection $baris): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $akun = Pengguna::query()->whereIn('id', $baris->flatMap(fn (PermintaanAkses $p) => [$p->pengguna_id, $p->diputuskan_oleh])->filter()->unique()->values())
            ->get(['id', 'pegawai_id', 'nama_pengguna'])->keyBy('id');
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id'));
        $izin = Izin::query()->whereIn('id', $baris->pluck('izin_id')->filter()->unique()->values())->get(['id', 'kode', 'menu_id', 'jenis_aksi'])->keyBy('id');
        $menu = Menu::query()->get(['id', 'induk_id', 'tingkat', 'kode', 'label'])->keyBy('id');
        $namaAkun = fn (Pengguna $a) => $nama[$a->pegawai_id] ?? $a->nama_pengguna;

        return $baris->map(function (PermintaanAkses $p) use ($akun, $izin, $menu, $namaAkun) {
            $pemohon = $akun->get($p->pengguna_id);
            $pemutus = $p->diputuskan_oleh === null ? null : $akun->get($p->diputuskan_oleh);
            $i = $p->izin_id === null ? null : $izin->get($p->izin_id);
            [$jenis, $kode, $label] = $i !== null
                ? ['izin', $i->kode, PohonMenu::jalurLabel($menu, $i->menu_id).' → '.PohonMenu::labelAksi($i->jenis_aksi)]
                : ['menu', (string) $menu->get((string) $p->menu_id)?->kode, PohonMenu::jalurLabel($menu, (string) $p->menu_id)];

            return [
                'id' => $p->id,
                'nomor' => $p->nomor,
                'akun' => ['id' => $p->pengguna_id, 'nama_pengguna' => (string) $pemohon?->nama_pengguna, 'nama' => $pemohon === null ? '' : $namaAkun($pemohon)],
                'sasaran_jenis' => $jenis,
                'sasaran_kode' => $kode,
                'sasaran_label' => $label,
                'alasan' => $p->alasan,
                'status' => $p->status,
                'diputuskan_oleh' => $pemutus === null ? null : ['id' => $pemutus->id, 'nama' => $namaAkun($pemutus)],
                'diputuskan_pada' => $p->diputuskan_pada?->toIso8601String(),
                'catatan_keputusan' => $p->catatan_keputusan,
                'dibuat_pada' => $p->dibuat_pada->toIso8601String(),
            ];
        })->values()->all();
    }
}
