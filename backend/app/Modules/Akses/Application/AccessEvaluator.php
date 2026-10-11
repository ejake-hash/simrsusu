<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Domain\HakAkses;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\PemberianAkses;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use Illuminate\Support\Collection;

/**
 * Satu-satunya penentu hak akses (PRD 9.2.5, D.4): dipakai Gate (lihat ModuleServiceProvider),
 * menu sidebar, dan pembentuk respons. Tiket 1.2a mengerjakan lapis 1 (menu/bagian), 2 (aksi),
 * dan 3 (cakupan data; cakupan `unit` memuat semua unit turunannya sejak tiket 1.8a); lapis 4–5 menyusul di tiket 1.5.
 *
 *   akun aktif dan tidak terkunci
 *   dan tidak ada pencabutan per akun atas sub-menu, bagian, atau aksi itu   ← cabut menang
 *   dan ada penugasan peran atau pemberian per akun yang berlaku sekarang yang memberinya,
 *       dengan cakupan yang memuat data itu.
 */
final class AccessEvaluator
{
    /** Batas atas lama cache bila tidak ada masa berlaku yang lebih dekat. */
    private const MAKS_CACHE_DETIK = 900;

    public function __construct(
        private readonly CacheHakAkses $cache,
        private readonly DataUnit $dataUnit,
    ) {}

    public function hak(Pengguna $pengguna): HakAkses
    {
        if (! $pengguna->aktif() || $pengguna->terkunci()) {
            return new HakAkses;
        }

        return $this->cache->ambil($pengguna->id, fn () => $this->hitung($pengguna));
    }

    /** Lapis 1: sub-menu, bagian, atau menu tanpa sub-menu. */
    public function bolehMenu(Pengguna $pengguna, string $kode): bool
    {
        return $this->hak($pengguna)->bolehMenu($kode);
    }

    /** Lapis 1–3. Tanpa $data: cukup punya izin itu di cakupan mana pun. */
    public function boleh(Pengguna $pengguna, string $izin, ?Cakupan $data = null): bool
    {
        $cakupan = $this->hak($pengguna)->cakupan($izin);
        if ($data === null) {
            return $cakupan !== [];
        }

        // Data milik sebuah unit: cakupan unit mana pun di jalur leluhurnya memuatnya.
        $leluhur = $data->jenis === 'unit' && $data->id !== null ? $this->dataUnit->leluhur($data->id) : [];

        return array_any($cakupan, fn (Cakupan $c) => $c->memuat($data)
            || ($c->jenis === 'unit' && in_array($c->id, $leluhur, true)));
    }

    /**
     * Unit akar cakupan sebuah izin untuk menyaring query daftar: null = seluruh RS (cakupan `rs`), list kosong
     * = tidak ada, selain itu id unit yang subpohonnya boleh (perluas dengan pohon unit).
     *
     * @return list<string>|null
     */
    public function unitTerjangkau(Pengguna $pengguna, string $izin): ?array
    {
        $unit = [];
        foreach ($this->cakupan($pengguna, $izin) as $c) {
            if ($c->jenis === 'rs') {
                return null;
            }
            if ($c->jenis === 'unit' && $c->id !== null) {
                $unit[] = $c->id;
            }
        }

        return array_values(array_unique($unit));
    }

    /**
     * Cakupan yang dimiliki untuk sebuah izin, untuk memaksakan filter di query daftar.
     *
     * @return list<Cakupan>
     */
    public function cakupan(Pengguna $pengguna, string $izin): array
    {
        return $this->hak($pengguna)->cakupan($izin);
    }

    /** @return array{0: HakAkses, 1: int} */
    private function hitung(Pengguna $pengguna): array
    {
        $sekarang = now();
        $penugasan = PenggunaPeran::query()->where('pengguna_id', $pengguna->id)->where('status', 'aktif')
            ->whereHas('peran', fn ($q) => $q->where('status', 'aktif'))->get();
        $khusus = PenggunaIzinKhusus::query()->where('pengguna_id', $pengguna->id)->where('status', 'aktif')->get();

        // Cache berakhir paling lambat saat penugasan/pemberian berikutnya mulai atau berhenti berlaku.
        $batas = collect([...$penugasan, ...$khusus])
            ->flatMap(fn (PemberianAkses $p) => [$p->berlaku_dari, $p->berlaku_sampai])
            ->filter(fn ($waktu) => $waktu !== null && $waktu->greaterThan($sekarang))
            ->min();
        $detik = $batas === null ? self::MAKS_CACHE_DETIK
            : max(1, min(self::MAKS_CACHE_DETIK, (int) ceil($sekarang->diffInSeconds($batas))));

        $berlaku = fn (PemberianAkses $p) => $p->berlaku_dari->lessThanOrEqualTo($sekarang)
            && ($p->berlaku_sampai === null || $p->berlaku_sampai->greaterThan($sekarang));
        $penugasan = $penugasan->filter($berlaku);
        $khusus = $khusus->filter($berlaku);
        $tambah = $khusus->where('efek', 'tambah');
        $cabut = $khusus->where('efek', 'cabut');

        $semuaMenu = Menu::query()->get(['id', 'induk_id', 'tingkat', 'kode', 'ikut_induk', 'aktif', 'selalu_tampil'])->keyBy('id');
        $anak = $semuaMenu->toBase()->groupBy('induk_id', preserveKeys: true);

        // Lapis 1.
        $diberi = PeranMenu::query()->whereIn('peran_id', $penugasan->pluck('peran_id'))->pluck('menu_id')
            ->merge($tambah->pluck('menu_id')->filter())
            ->merge($semuaMenu->where('selalu_tampil', true)->keys());
        $diberi = $diberi->merge($diberi->flatMap(fn ($id) => ($anak[$id] ?? collect())
            ->where('tingkat', 'bagian')->where('ikut_induk', true)->keys()));
        $dicabut = $cabut->pluck('menu_id')->filter()->flatMap(fn ($id) => [$id, ...$this->keturunan($id, $anak)]);
        $menuBoleh = $diberi->unique()->diff($dicabut)
            ->filter(fn ($id) => $this->aktifSampaiAkar($id, $semuaMenu))
            ->flip();

        // Lapis 2 dan 3: setiap izin membawa cakupan penugasan/pemberian yang memberikannya.
        $cakupanPeran = $penugasan->groupBy('peran_id');
        $cakupanIzin = [];
        foreach (PeranIzin::query()->whereIn('peran_id', $cakupanPeran->keys())->get(['peran_id', 'izin_id']) as $pi) {
            foreach ($cakupanPeran[$pi->peran_id] as $p) {
                $cakupanIzin[$pi->izin_id][] = [$p->cakupan_jenis, $p->cakupan_id];
            }
        }
        foreach ($tambah->whereNotNull('izin_id') as $p) {
            $cakupanIzin[$p->izin_id][] = [$p->cakupan_jenis, $p->cakupan_id];
        }
        $izinDicabut = $cabut->pluck('izin_id')->filter()->flip();

        $izin = [];
        foreach (Izin::query()->whereIn('id', array_keys($cakupanIzin))->get(['id', 'kode', 'menu_id']) as $i) {
            if (! isset($izinDicabut[$i->id]) && isset($menuBoleh[$i->menu_id])) {
                $izin[$i->kode] = array_values(array_unique($cakupanIzin[$i->id], SORT_REGULAR));
            }
        }
        ksort($izin);

        $menu = array_fill_keys($semuaMenu->only($menuBoleh->keys()->all())->pluck('kode')->all(), true);

        return [new HakAkses($menu, $izin), $detik];
    }

    /**
     * @param  Collection<array-key, Collection<array-key, Menu>>  $anak
     * @return list<string>
     */
    private function keturunan(string $id, Collection $anak): array
    {
        $hasil = [];
        foreach (($anak[$id] ?? collect())->keys() as $idAnak) {
            $hasil = [...$hasil, $idAnak, ...$this->keturunan($idAnak, $anak)];
        }

        return $hasil;
    }

    /** @param  Collection<string, Menu>  $semuaMenu */
    private function aktifSampaiAkar(string $id, Collection $semuaMenu): bool
    {
        for ($menu = $semuaMenu[$id] ?? null; $menu !== null; $menu = $semuaMenu[$menu->induk_id] ?? null) {
            if (! $menu->aktif) {
                return false;
            }
        }

        return isset($semuaMenu[$id]);
    }
}
