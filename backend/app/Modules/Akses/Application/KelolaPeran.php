<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PersetujuanAkses;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pengaturan → Peran & izin (tiket 1.2c-be, PRD 9.2.3–9.3, kontrak /peran*).
 *
 * - Izin peran disimpan per kode izin; peran_menu diturunkan dari izin `lihat` (lapis 1 = node yang
 *   boleh dilihat). Izin dan sub-menu yang dicabut berstatus batal, tidak dihapus.
 * - Izin sensitif baru tidak langsung berlaku: masing-masing menjadi persetujuan pemilik data (tiket 1.2d,
 *   AlurPersetujuan); izin lain langsung berlaku. Peran bawaan sistem (superadmin) terkunci dari layar.
 * - Versi = akses.peran.revisi, dinaikkan dengan compare-and-set di awal transaksi: dua penyunting dengan
 *   versi sama → yang kedua 412 versi_berubah, tidak ada yang tertimpa diam-diam.
 * - Audit dicatat di akhir transaksi (ADR 0003). Cache hak akses semua akun dihapus sekali per perubahan.
 */
final class KelolaPeran
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly PenjagaPenyunting $penjaga,
        private readonly CacheHakAkses $cache,
        private readonly AlurPersetujuan $alur,
    ) {}

    /** @param  array{kode: string, nama: string, uraian?: string|null, wajib_mfa: bool, salin_dari_id?: string|null, dasar_perubahan: string}  $isi */
    public function buat(Pengguna $penyunting, array $isi): Peran
    {
        try {
            return DB::transaction(function () use ($penyunting, $isi) {
                if (Peran::query()->where('kode', $isi['kode'])->exists()) {
                    throw $this->kodeSudahDipakai();
                }

                $peran = new Peran(['kode' => $isi['kode'], 'nama' => $isi['nama'], 'uraian' => $isi['uraian'] ?? null, 'wajib_mfa' => $isi['wajib_mfa']]);
                $peran->forceFill(['dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();

                // Salinan tanpa izin sensitif: pemberiannya butuh persetujuan pemilik data (tiket 1.2d).
                $sumber = $isi['salin_dari_id'] ?? null;
                $izin = $sumber === null ? collect() : Izin::query()->where('sensitif', false)
                    ->whereIn('id', DB::table('akses.peran_izin')->where('peran_id', $sumber)->where('status', 'aktif')->select('izin_id'))
                    ->get(['id', 'kode', 'menu_id', 'jenis_aksi']);
                $this->tulisIzin($peran, $izin, $penyunting, $isi['dasar_perubahan']);

                $this->audit->catat('akses.peran_dibuat', $penyunting->id, 'akses.peran', $peran->id, [
                    ...$this->isiAudit($peran),
                    'izin' => $izin->pluck('kode')->sort()->values()->all(),
                    'salin_dari_id' => $sumber,
                    'dasar_perubahan' => $isi['dasar_perubahan'],
                ]);

                return $peran;
            });
        } catch (QueryException $e) {
            // Dua permintaan bersamaan dengan kode yang sama: yang kalah melanggar indeks unik.
            throw $e->getCode() === '23505' ? $this->kodeSudahDipakai() : $e;
        }
    }

    /** @param  array{nama?: string, uraian?: string|null, wajib_mfa?: bool, status?: string, dasar_perubahan: string}  $isi */
    public function ubah(Pengguna $penyunting, Peran $peran, string $versi, array $isi): Peran
    {
        $this->penjaga->pastikanBukanPeranSendiri($penyunting, $peran->id);

        return DB::transaction(function () use ($penyunting, $peran, $versi, $isi) {
            $this->naikkanVersi($peran, $versi, $penyunting);

            if (($isi['wajib_mfa'] ?? true) === false && $this->mfaTerkunci($peran)) {
                throw new ApiError(422, 'mfa_tidak_bisa_dimatikan', 'MFA tidak bisa dimatikan untuk superadmin atau peran yang memuat izin sensitif.');
            }
            if ($peran->bawaan_sistem) {
                throw $this->bawaanTerkunci();
            }
            if (($isi['status'] ?? null) === 'nonaktif' && $peran->status === 'aktif'
                && ($jumlah = $this->jumlahPenugasanAktif($peran)) > 0) {
                throw new ApiError(409, 'peran_masih_dipakai', "Peran masih ditugaskan ke {$jumlah} akun. Akhiri penugasannya lebih dulu.", [
                    ['jumlah_akun' => $jumlah],
                ]);
            }

            $sebelum = $this->isiAudit($peran);
            $peran->fill(array_intersect_key($isi, array_flip(['nama', 'uraian', 'wajib_mfa', 'status'])));
            $peran->forceFill(['diubah_oleh' => $penyunting->id])->save();

            $this->audit->catat('akses.peran_diubah', $penyunting->id, 'akses.peran', $peran->id,
                [...$this->isiAudit($peran), 'dasar_perubahan' => $isi['dasar_perubahan']], $sebelum);

            return $peran;
        });
    }

    /**
     * Ganti utuh himpunan izin peran (simpan matriks).
     *
     * @param  list<string>  $kodeIzin
     * @return array{peran: Peran, ditambah: list<string>, dicabut: list<string>, akun_terdampak: int, menunggu_persetujuan: list<PersetujuanAkses>}
     */
    public function gantiIzin(Pengguna $penyunting, Peran $peran, string $versi, array $kodeIzin, string $dasar, ?string $alasan): array
    {
        $this->penjaga->pastikanBukanPeranSendiri($penyunting, $peran->id);

        return DB::transaction(function () use ($penyunting, $peran, $versi, $kodeIzin, $dasar, $alasan) {
            $this->naikkanVersi($peran, $versi, $penyunting);
            if ($peran->bawaan_sistem) {
                throw $this->bawaanTerkunci();
            }

            $izin = $this->validasiMatriks($kodeIzin);
            $lama = $this->izinAktif($peran);
            $ditambah = $izin->whereNotIn('id', $lama->pluck('id'));
            $dicabut = $lama->whereNotIn('id', $izin->pluck('id'));

            // Izin sensitif baru menunggu persetujuan; yang sudah menunggu tidak diajukan ulang (idempoten).
            $sensitifBaru = $ditambah->where('sensitif', true)->values();
            $penyetuju = $this->alur->penyetujuIzin(Izin::query()->whereIn('id', $sensitifBaru->pluck('id'))->get());
            $menunggu = PersetujuanAkses::query()->menunggu()->where('objek_jenis', 'izin_peran')->where('peran_id', $peran->id)
                ->whereIn('izin_id', $sensitifBaru->pluck('id'))->get()->keyBy('izin_id');
            $persetujuan = [];
            foreach ($sensitifBaru as $i) {
                $persetujuan[] = $menunggu[$i->id] ?? $this->alur->ajukan($penyunting, 'izin_sensitif', 'izin_peran',
                    ['peran_id' => $peran->id, 'izin_id' => $i->id], [$penyetuju[$i->id]], ['peran_id' => $peran->id, 'izin_id' => $i->id], $dasar, $alasan)[0];
            }
            // Izin sensitif yang masih menunggu tetapi dikeluarkan dari matriks: pengajuannya ditarik, tidak berlaku belakangan.
            $ditarik = PersetujuanAkses::query()->menunggu()->where('objek_jenis', 'izin_peran')->where('peran_id', $peran->id)
                ->whereNotIn('izin_id', $izin->pluck('id'))->get();
            foreach ($ditarik as $p) {
                $this->alur->tarik($penyunting, $p, 'Izin dikeluarkan dari matriks peran oleh penyunting.');
            }
            $ditambah = $ditambah->where('sensitif', false)->values();
            $izin = $izin->reject(fn (Izin $i) => $sensitifBaru->contains('id', $i->id))->values();

            $this->tulisIzin($peran, $izin, $penyunting, $dasar);
            $akunTerdampak = $peran->jumlahAkunBerlaku();
            $hasil = [
                'peran' => $peran,
                'ditambah' => $ditambah->pluck('kode')->sort()->values()->all(),
                'dicabut' => $dicabut->pluck('kode')->sort()->values()->all(),
                'akun_terdampak' => $akunTerdampak,
                'menunggu_persetujuan' => $persetujuan,
            ];

            $this->audit->catat('akses.izin_diganti', $penyunting->id, 'akses.peran', $peran->id, [
                'izin' => $izin->pluck('kode')->sort()->values()->all(),
                'ditambah' => $hasil['ditambah'],
                'dicabut' => $hasil['dicabut'],
                'akun_terdampak' => $akunTerdampak,
                'menunggu_persetujuan' => array_map(fn (PersetujuanAkses $p) => $p->nomor, $persetujuan),
                'persetujuan_ditarik' => $ditarik->pluck('nomor')->all(),
                'dasar_perubahan' => $dasar,
                'alasan' => $alasan,
            ], ['izin' => $lama->pluck('kode')->sort()->values()->all()]);

            return $hasil;
        });
    }

    /** Superadmin selalu, atau peran yang memuat izin sensitif (PRD 10.3.2). */
    public function mfaTerkunci(Peran $peran): bool
    {
        return $peran->kode === MenuBawaan::PERAN_SUPERADMIN || $peran->memuatIzinSensitif();
    }

    /** @return Collection<int, Izin> */
    public function izinAktif(Peran $peran): Collection
    {
        return Izin::query()->whereIn('id', DB::table('akses.peran_izin')->where('peran_id', $peran->id)->where('status', 'aktif')->select('izin_id'))
            ->get(['id', 'kode', 'menu_id', 'jenis_aksi', 'sensitif'])->toBase();
    }

    /**
     * Validasi isi matriks (kontrak gantiIzinPeran, PRD 9.3 MD-3); 422 dengan kode yang salah di rincian.
     *
     * @param  list<string>  $kodeIzin
     * @return Collection<int, Izin>
     */
    private function validasiMatriks(array $kodeIzin): Collection
    {
        $izin = Izin::query()->whereIn('kode', $kodeIzin)->get(['id', 'kode', 'menu_id', 'jenis_aksi', 'sensitif'])->toBase();
        $tidakDikenal = array_values(array_diff($kodeIzin, $izin->pluck('kode')->all()));
        if ($tidakDikenal !== []) {
            throw $this->matriksSalah('izin_tidak_dikenal', 'Ada kode izin yang tidak dikenal.', $tidakDikenal, 'Kode izin tidak dikenal');
        }

        // Node yang diberi `lihat` = node yang diberikan. Node tanpa izin lihat terdaftar tidak mensyaratkannya.
        $menu = Menu::query()->get(['id', 'induk_id', 'tingkat', 'kode'])->keyBy('id');
        $izinLihat = Izin::query()->where('jenis_aksi', 'lihat')->pluck('id', 'menu_id');
        $diberi = $izin->where('jenis_aksi', 'lihat')->pluck('menu_id')->flip();
        $diberikan = fn (string $menuId) => isset($diberi[$menuId]) || ! isset($izinLihat[$menuId]);

        $tanpaLihat = $izin->filter(fn (Izin $i) => ! $diberikan($i->menu_id))->pluck('kode')->sort()->values()->all();
        if ($tanpaLihat !== []) {
            throw $this->matriksSalah('lihat_wajib', 'Aksi apa pun mensyaratkan izin lihat pada node yang sama.', $tanpaLihat, 'Izin lihat pada node ini belum diberikan');
        }

        // Bagian mensyaratkan induk terdekat yang punya izin lihat sudah diberikan (MD-3).
        $tanpaInduk = $izin->filter(function (Izin $i) use ($menu, $izinLihat, $diberikan) {
            if ($menu[$i->menu_id]->tingkat !== 'bagian') {
                return false;
            }
            for ($induk = $menu[$menu[$i->menu_id]->induk_id] ?? null; $induk !== null; $induk = $menu[$induk->induk_id] ?? null) {
                if (isset($izinLihat[$induk->id])) {
                    return ! $diberikan($induk->id);
                }
            }

            return false;
        })->pluck('kode')->sort()->values()->all();
        if ($tanpaInduk !== []) {
            throw $this->matriksSalah('induk_belum_diberikan', 'Bagian hanya bisa diberikan bila sub-menu induknya diberi izin lihat.', $tanpaInduk, 'Induk bagian ini belum diberi izin lihat');
        }

        return $izin;
    }

    /**
     * Tulis himpunan izin peran dan turunkan peran_menu dari izin lihat. Baris lama tidak dihapus:
     * yang dicabut berstatus batal, yang diberikan lagi diaktifkan kembali.
     *
     * @param  Collection<int, Izin>  $izin
     */
    private function tulisIzin(Peran $peran, Collection $izin, Pengguna $penyunting, string $dasar): void
    {
        $this->tulisRelasi('akses.peran_izin', 'izin_id', $peran, $izin->pluck('id')->all(), $penyunting, $dasar);
        $this->tulisRelasi('akses.peran_menu', 'menu_id', $peran, $izin->where('jenis_aksi', 'lihat')->pluck('menu_id')->unique()->values()->all(), $penyunting, $dasar);
        $this->cache->lupakanSemua();
    }

    /** @param  list<string>  $target */
    private function tulisRelasi(string $tabel, string $kolom, Peran $peran, array $target, Pengguna $penyunting, string $dasar): void
    {
        $sekarang = now();
        $ada = DB::table($tabel)->where('peran_id', $peran->id)->pluck('status', $kolom);

        $dicabut = $ada->filter(fn ($status, $id) => $status === 'aktif' && ! in_array($id, $target, true))->keys()->all();
        if ($dicabut !== []) {
            DB::table($tabel)->where('peran_id', $peran->id)->whereIn($kolom, $dicabut)->update([
                'status' => 'batal', 'alasan_batal' => $dasar, 'dibatalkan_oleh' => $penyunting->id, 'dibatalkan_pada' => $sekarang,
                'diubah_oleh' => $penyunting->id, 'diubah_pada' => $sekarang,
            ]);
        }

        $aktifKembali = array_values(array_filter($target, fn ($id) => ($ada[$id] ?? null) === 'batal'));
        if ($aktifKembali !== []) {
            DB::table($tabel)->where('peran_id', $peran->id)->whereIn($kolom, $aktifKembali)->update([
                'status' => 'aktif', 'alasan_batal' => null, 'dibatalkan_oleh' => null, 'dibatalkan_pada' => null,
                'diubah_oleh' => $penyunting->id, 'diubah_pada' => $sekarang,
            ]);
        }

        $baru = array_values(array_filter($target, fn ($id) => ! isset($ada[$id])));
        foreach (array_chunk($baru, 500) as $potongan) {
            DB::table($tabel)->insert(array_map(fn ($id) => [
                'id' => (string) Str::uuid7(), 'peran_id' => $peran->id, $kolom => $id,
                'dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id,
            ], $potongan));
        }
    }

    private function naikkanVersi(Peran $peran, string $versi, Pengguna $penyunting): void
    {
        // Versi = revisi (integer 32-bit); angka yang lebih panjang tidak mungkin cocok (bukan 500).
        $naik = ctype_digit($versi) && strlen($versi) <= 9 && Peran::query()->whereKey($peran->id)->where('revisi', (int) $versi)
            ->update(['revisi' => DB::raw('revisi + 1'), 'diubah_oleh' => $penyunting->id]) === 1;
        if (! $naik) {
            throw new ApiError(412, 'versi_berubah', 'Peran ini diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.');
        }
        $peran->refresh();
    }

    private function jumlahPenugasanAktif(Peran $peran): int
    {
        return PenggunaPeran::query()->where('peran_id', $peran->id)->masihAktif()->distinct()->count('pengguna_id');
    }

    /** @return array<string, mixed> */
    private function isiAudit(Peran $peran): array
    {
        return $peran->only(['kode', 'nama', 'uraian', 'wajib_mfa', 'status']);
    }

    /** @param  list<string>  $kode */
    private function matriksSalah(string $kodeError, string $pesan, array $kode, string $pesanBaris): ApiError
    {
        return new ApiError(422, $kodeError, $pesan, array_map(fn ($k) => ['kolom' => 'izin', 'kode' => $k, 'pesan' => $pesanBaris], $kode));
    }

    private function bawaanTerkunci(): ApiError
    {
        return new ApiError(409, 'peran_bawaan_terkunci', 'Peran bawaan sistem tidak bisa diubah dari layar. Perubahannya disetujui superadmin lain (tiket persetujuan akses).');
    }

    private function kodeSudahDipakai(): ApiError
    {
        return new ApiError(409, 'kode_sudah_dipakai', 'Kode peran sudah dipakai.', [['kolom' => 'kode', 'pesan' => 'Kode peran sudah dipakai.']]);
    }
}
