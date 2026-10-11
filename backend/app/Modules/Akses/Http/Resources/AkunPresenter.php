<?php

namespace App\Modules\Akses\Http\Resources;

use App\Core\Http\ApiError;
use App\Modules\Akses\Application\KebijakanMfa;
use App\Modules\Akses\Application\PenjagaPenyunting;
use App\Modules\Akses\Application\PohonMenu;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\PemberianAkses;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Collection;

/**
 * Bentuk respons Akun & akses (kontrak AkunRingkas, AkunRinci, Penugasan, IzinKhusus, StatusMfaAkun).
 * Nama pegawai, pelaku, dan label sasaran dimuat sekaligus per halaman, bukan per baris. Tidak pernah
 * mengirim NIP, email, rahasia MFA, atau kode cadangan.
 */
final class AkunPresenter
{
    public function __construct(
        private readonly RingkasanPegawai $pegawai,
        private readonly KebijakanMfa $kebijakanMfa,
        private readonly PenjagaPenyunting $penjaga,
    ) {}

    /**
     * @param  Collection<int, Pengguna>  $akun
     * @return list<array<string, mixed>>
     */
    public function ringkas(Collection $akun): array
    {
        $id = $akun->pluck('id')->all();
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id'));
        $peran = PenggunaPeran::query()->berlaku()->whereIn('pengguna_id', $id)->with('peran:id,nama')
            ->orderBy('berlaku_dari')->get()->groupBy('pengguna_id');
        $pengecualian = PenggunaIzinKhusus::query()->berlaku()->whereIn('pengguna_id', $id)
            ->selectRaw('pengguna_id, efek, count(*) as jumlah')->groupBy('pengguna_id', 'efek')->get()
            ->groupBy('pengguna_id');

        return $akun->map(function (Pengguna $a) use ($nama, $peran, $pengecualian) {
            $hitung = ($pengecualian[$a->id] ?? collect())->pluck('jumlah', 'efek');

            return [
                ...$this->dasar($a, $nama),
                'peran' => ($peran[$a->id] ?? collect())->map(fn (PenggunaPeran $p) => [
                    'id' => $p->peran->id, 'nama' => $p->peran->nama, 'berlaku_sampai' => $p->berlaku_sampai?->toIso8601String(),
                ])->values()->all(),
                'pengecualian' => ['tambah' => (int) ($hitung['tambah'] ?? 0), 'cabut' => (int) ($hitung['cabut'] ?? 0)],
                'mfa_aktif' => $a->mfaAktif(),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function rinci(Pengguna $akun, Pengguna $penyunting): array
    {
        $penugasan = $this->urutRiwayat(PenggunaPeran::query()->where('pengguna_id', $akun->id)->with('peran:id,kode,nama')->get());
        $khusus = $this->urutRiwayat(PenggunaIzinKhusus::query()->where('pengguna_id', $akun->id)->with('izin:id,kode,menu_id,jenis_aksi,sensitif')->get());
        $pelaku = $this->pelaku([...$penugasan, ...$khusus]);
        $menu = Menu::query()->get(['id', 'induk_id', 'tingkat', 'kode', 'label'])->keyBy('id');

        return [
            ...$this->dasar($akun, $this->pegawai->nama([$akun->pegawai_id])),
            'diri_sendiri' => $akun->id === $penyunting->id,
            'mfa' => $this->statusMfa($akun, $penyunting),
            'penugasan' => $penugasan->map(fn (PenggunaPeran $p) => $this->penugasan($p, $pelaku))->values()->all(),
            'izin_khusus' => $khusus->map(fn (PenggunaIzinKhusus $k) => $this->izinKhusus($k, $pelaku, $menu))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function satuPenugasan(PenggunaPeran $penugasan): array
    {
        $penugasan->loadMissing('peran:id,kode,nama');

        return $this->penugasan($penugasan, $this->pelaku([$penugasan]));
    }

    /** @return array<string, mixed> */
    public function satuIzinKhusus(PenggunaIzinKhusus $khusus): array
    {
        $khusus->loadMissing('izin:id,kode,menu_id,jenis_aksi,sensitif');
        $menu = Menu::query()->get(['id', 'induk_id', 'tingkat', 'kode', 'label'])->keyBy('id');

        return $this->izinKhusus($khusus, $this->pelaku([$khusus]), $menu);
    }

    /** @return array{aktif: bool, aktif_pada: string|null, wajib_akun: bool, sumber_wajib: list<array<string, mixed>>, dapat_direset: bool} */
    public function statusMfa(Pengguna $akun, Pengguna $penyunting): array
    {
        return [
            'aktif' => $akun->mfaAktif(),
            'aktif_pada' => $akun->mfa_aktif_pada?->toIso8601String(),
            'wajib_akun' => $akun->wajib_mfa,
            'sumber_wajib' => $this->kebijakanMfa->sumberWajib($akun),
            'dapat_direset' => $akun->mfaAktif() && $this->bolehMengubah($penyunting, $akun),
        ];
    }

    private function bolehMengubah(Pengguna $penyunting, Pengguna $akun): bool
    {
        try {
            $this->penjaga->pastikanBolehMengubahAkun($penyunting, $akun);

            return true;
        } catch (ApiError) {
            return false;
        }
    }

    /**
     * @param  array<string, string>  $namaPegawai
     * @return array<string, mixed>
     */
    private function dasar(Pengguna $a, array $namaPegawai): array
    {
        return [
            'id' => $a->id,
            'nama_pengguna' => $a->nama_pengguna,
            'nama' => $namaPegawai[$a->pegawai_id] ?? '',
            'status' => $a->status,
            'terkunci_sampai' => $a->terkunci() ? $a->terkunci_sampai?->toIso8601String() : null,
            'login_terakhir_pada' => $a->login_terakhir_pada?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, array{id: string, nama: string}>  $pelaku
     * @return array<string, mixed>
     */
    private function penugasan(PenggunaPeran $p, array $pelaku): array
    {
        return [
            'id' => $p->id,
            'peran' => ['id' => $p->peran->id, 'kode' => $p->peran->kode, 'nama' => $p->peran->nama],
            'cakupan_jenis' => $p->cakupan_jenis,
            'cakupan_id' => $p->cakupan_id,
            ...$this->pemberian($p, $pelaku),
        ];
    }

    /**
     * @param  array<string, array{id: string, nama: string}>  $pelaku
     * @param  Collection<string, Menu>  $menu
     * @return array<string, mixed>
     */
    private function izinKhusus(PenggunaIzinKhusus $k, array $pelaku, Collection $menu): array
    {
        $izin = $k->izin;
        [$jenis, $kode, $label] = $izin !== null
            ? ['izin', $izin->kode, PohonMenu::jalurLabel($menu, $izin->menu_id).' → '.PohonMenu::labelAksi($izin->jenis_aksi)]
            : ['menu', (string) $menu->get((string) $k->menu_id)?->kode, PohonMenu::jalurLabel($menu, (string) $k->menu_id)];

        return [
            'id' => $k->id,
            'efek' => $k->efek,
            'sasaran_jenis' => $jenis,
            'sasaran_kode' => $kode,
            'sasaran_label' => $label,
            'sensitif' => $izin !== null && $izin->sensitif,
            ...$this->pemberian($k, $pelaku),
            'alasan' => (string) $k->alasan,
        ];
    }

    /**
     * @param  array<string, array{id: string, nama: string}>  $pelaku
     * @return array<string, mixed>
     */
    private function pemberian(PemberianAkses $p, array $pelaku): array
    {
        $berlaku = $p->status === 'aktif' && $p->berlaku_dari->lessThanOrEqualTo(now())
            && ($p->berlaku_sampai === null || $p->berlaku_sampai->greaterThan(now()));
        $diberikanOleh = $p->getAttribute('diberikan_oleh');
        $dibatalkanOleh = $p->getAttribute('dibatalkan_oleh');

        return [
            'berlaku_dari' => $p->berlaku_dari->toIso8601String(),
            'berlaku_sampai' => $p->berlaku_sampai?->toIso8601String(),
            'dasar_pemberian' => $p->dasar_pemberian,
            'alasan' => $p->alasan,
            'diberikan_oleh' => $diberikanOleh === null ? null : ($pelaku[$diberikanOleh] ?? null),
            'status' => $p->status,
            'berlaku_sekarang' => $berlaku,
            'alasan_batal' => $p->getAttribute('alasan_batal'),
            'dibatalkan_oleh' => $dibatalkanOleh === null ? null : ($pelaku[$dibatalkanOleh] ?? null),
            'dibatalkan_pada' => $p->getAttribute('dibatalkan_pada')?->toIso8601String(),
            'dibuat_pada' => $p->getAttribute('dibuat_pada')?->toIso8601String(),
        ];
    }

    /**
     * Berlaku/akan berlaku dulu (mulai paling awal), lalu riwayat batal/kedaluwarsa terbaru dulu.
     *
     * @template T of PemberianAkses
     *
     * @param  Collection<int, T>  $baris
     * @return Collection<int, T>
     */
    private function urutRiwayat(Collection $baris): Collection
    {
        $masihAktif = fn (PemberianAkses $p) => $p->status === 'aktif' && ($p->berlaku_sampai === null || $p->berlaku_sampai->isFuture());
        [$aktif, $riwayat] = $baris->partition($masihAktif);

        return $aktif->sortBy(fn (PemberianAkses $p) => $p->berlaku_dari->getPreciseTimestamp(6))
            ->concat($riwayat->sortByDesc(fn (PemberianAkses $p) => $p->getAttribute('diubah_pada')?->getPreciseTimestamp(6)))
            ->values();
    }

    /**
     * Nama pelaku (pemberi dan pembatal) per id akun.
     *
     * @param  iterable<PemberianAkses>  $baris
     * @return array<string, array{id: string, nama: string}>
     */
    private function pelaku(iterable $baris): array
    {
        $id = [];
        foreach ($baris as $p) {
            $id[] = $p->getAttribute('diberikan_oleh');
            $id[] = $p->getAttribute('dibatalkan_oleh');
        }
        $akun = Pengguna::query()->whereIn('id', array_values(array_unique(array_filter($id))))->get(['id', 'pegawai_id', 'nama_pengguna']);
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id'));

        return $akun->mapWithKeys(fn (Pengguna $a) => [$a->id => ['id' => $a->id, 'nama' => $nama[$a->pegawai_id] ?? $a->nama_pengguna]])->all();
    }
}
