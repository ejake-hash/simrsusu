<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\PemberianAkses;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PersetujuanAkses;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan → Akun & akses (tiket 1.2c-be, PRD 9.2.3–9.3, 10.3.2, kontrak /akun/{id}/*).
 *
 * - Penyunting tidak pernah mengubah akses akunnya sendiri (403 diri_sendiri), dan akun superadmin hanya
 *   diubah superadmin lain (403 akun_superadmin, PRD 9.2.4).
 * - Hanya cakupan seluruh RS sampai inti.unit_organisasi ada (tiket 1.8a).
 * - Peran superadmin dan izin sensitif tidak diberikan dari layar sampai alur persetujuan (tiket 1.2d).
 * - Mengakhiri penugasan/pengecualian = status batal dengan alasan; barisnya tetap sebagai riwayat (MD-1).
 * - Reset MFA dan wajib MFA baru untuk akun tanpa MFA memutus sesi akun itu (sesi_dicabut_pada, ADR 0002).
 *   Perubahan peran/penugasan cukup menghapus cache: izin diperiksa ulang setiap permintaan.
 * - Cache hak akses akun terdampak dihapus oleh model (PemberianAkses::saved); audit di akhir transaksi.
 */
final class KelolaAksesAkun
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly PenjagaPenyunting $penjaga,
        private readonly DataUnit $dataUnit,
        private readonly AlurPersetujuan $alur,
        private readonly KelolaPermintaanAkses $permintaan,
        private readonly KelolaPerangkat $perangkat,
    ) {}

    /**
     * Penugasan langsung, atau pengajuan persetujuan (1.2d) untuk peran superadmin (K4) dan peran berizin sensitif (K3).
     *
     * @param  array{peran_id: string, cakupan_jenis: string, cakupan_id?: string|null, berlaku_dari?: string|null, berlaku_sampai?: string|null, dasar_pemberian: string, alasan?: string|null, permintaan_akses_id?: string|null}  $isi
     */
    public function tugaskan(Pengguna $penyunting, Pengguna $akun, array $isi): PenggunaPeran|PersetujuanAkses
    {
        $this->penjaga->pastikanBolehMengubahAkun($penyunting, $akun);
        $cakupanId = $this->pastikanCakupan($isi['cakupan_jenis'], $isi['cakupan_id'] ?? null);
        [$dari, $sampai] = $this->masaBerlaku($isi);

        return DB::transaction(function () use ($penyunting, $akun, $isi, $dari, $sampai, $cakupanId) {
            // Penugasan bersamaan ke akun yang sama tidak boleh sama-sama lolos cek ganda di bawah.
            Pengguna::query()->whereKey($akun->id)->lockForUpdate()->first();
            // Dikunci: penonaktifan peran yang bersamaan tidak bisa lolos dari cek status di bawah.
            $peran = Peran::query()->lockForUpdate()->findOrFail($isi['peran_id']);

            if ($peran->status !== 'aktif') {
                throw new ApiError(409, 'peran_nonaktif', 'Peran ini nonaktif dan tidak bisa ditugaskan.');
            }
            $ganda = PenggunaPeran::query()->where('pengguna_id', $akun->id)->where('peran_id', $peran->id)
                ->where('cakupan_jenis', $isi['cakupan_jenis'])
                ->when($cakupanId, fn ($q, $id) => $q->where('cakupan_id', $id), fn ($q) => $q->whereNull('cakupan_id'));
            if ($ganda->masihAktif()->exists()) {
                throw new ApiError(409, 'penugasan_ganda', 'Akun ini sudah punya penugasan aktif untuk peran dan cakupan yang sama.');
            }
            $this->permintaan->tindaklanjuti($isi['permintaan_akses_id'] ?? null, $akun, $penyunting);

            // Menunggu persetujuan, satu baris per peran penyetuju: superadmin (K4); peran berizin sensitif (K3, pemilik
            // data tiap izin); peran yang menjadi penyetuju izin sensitif (superadmin lain, K13: satu superadmin tidak
            // bisa membuat penyetuju sendiri lalu menyetujui lewat peran itu). Dihitung ulang saat diterapkan.
            $penyetuju = $this->alur->penyetujuPenugasan($peran);
            if ($penyetuju !== []) {
                $menunggu = PersetujuanAkses::query()->menunggu()->where('objek_jenis', 'penugasan')->where('sasaran_pengguna_id', $akun->id)
                    ->where('peran_id', $peran->id)->get()->contains(fn (PersetujuanAkses $p) => $p->objek['cakupan_jenis'] === $isi['cakupan_jenis']
                        && ($p->objek['cakupan_id'] ?? null) === $cakupanId);
                if ($menunggu) {
                    throw $this->persetujuanGanda();
                }

                return $this->alur->ajukan($penyunting, $peran->kode === MenuBawaan::PERAN_SUPERADMIN ? 'peran_superadmin' : 'izin_sensitif', 'penugasan', [
                    'pengguna_id' => $akun->id, 'peran_id' => $peran->id, 'cakupan_jenis' => $isi['cakupan_jenis'], 'cakupan_id' => $cakupanId,
                    'berlaku_dari' => $dari->toIso8601String(), 'berlaku_sampai' => $sampai?->toIso8601String(),
                    'dasar_pemberian' => $isi['dasar_pemberian'], 'alasan' => $isi['alasan'] ?? null,
                ], $penyetuju, ['peran_id' => $peran->id, 'sasaran_pengguna_id' => $akun->id], $isi['dasar_pemberian'], $isi['alasan'] ?? null)[0];
            }

            $penugasan = new PenggunaPeran([
                'pengguna_id' => $akun->id, 'peran_id' => $peran->id, 'cakupan_jenis' => $isi['cakupan_jenis'], 'cakupan_id' => $cakupanId,
                'berlaku_dari' => $dari, 'berlaku_sampai' => $sampai,
                'dasar_pemberian' => $isi['dasar_pemberian'], 'alasan' => $isi['alasan'] ?? null, 'diberikan_oleh' => $penyunting->id,
            ]);
            $penugasan->forceFill(['status' => 'aktif', 'dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();

            $this->audit->catat('akses.penugasan_dibuat', $penyunting->id, 'akses.pengguna', $akun->id, [
                'penugasan_id' => $penugasan->id, 'peran' => $peran->kode, ...$this->isiPemberian($penugasan),
            ]);

            return $penugasan;
        });
    }

    /** Penugasan superadmin: pengajuan persetujuan superadmin lain (K4), minimal 2 superadmin tetap (PRD 9.2.4). */
    public function batalkanPenugasan(Pengguna $penyunting, Pengguna $akun, PenggunaPeran $penugasan, string $alasan): PenggunaPeran|PersetujuanAkses
    {
        $this->penjaga->pastikanBolehMengubahAkun($penyunting, $akun);

        return DB::transaction(function () use ($penyunting, $akun, $penugasan, $alasan) {
            $penugasan = PenggunaPeran::query()->whereKey($penugasan->id)->lockForUpdate()->firstOrFail();
            if ($penugasan->peran->kode === MenuBawaan::PERAN_SUPERADMIN && $penugasan->status === 'aktif') {
                DB::select("SELECT pg_advisory_xact_lock(hashtext('akses.superadmin_pertama'))");
                if (app(SuperadminPertama::class)->jumlahAktif() <= SuperadminPertama::BATAS) {
                    throw new ApiError(409, 'superadmin_minimal', 'Superadmin aktif tinggal '.SuperadminPertama::BATAS.'; tambahkan superadmin lain lebih dulu (PRD 9.2.4).');
                }
                $menunggu = PersetujuanAkses::query()->menunggu()->where('objek_jenis', 'pengakhiran_penugasan')
                    ->where('sasaran_pengguna_id', $akun->id)->get()->contains(fn (PersetujuanAkses $p) => $p->objek['penugasan_id'] === $penugasan->id);
                if ($menunggu) {
                    throw $this->persetujuanGanda();
                }

                return $this->alur->ajukan($penyunting, 'peran_superadmin', 'pengakhiran_penugasan',
                    ['penugasan_id' => $penugasan->id, 'pengguna_id' => $akun->id, 'peran_id' => $penugasan->peran_id, 'alasan_batal' => $alasan],
                    [$this->alur->peranSuperadmin()], ['peran_id' => $penugasan->peran_id, 'sasaran_pengguna_id' => $akun->id], $alasan, $alasan)[0];
            }
            $this->batalkan($penugasan, $penyunting, $alasan);
            $this->audit->catat('akses.penugasan_dibatalkan', $penyunting->id, 'akses.pengguna', $akun->id, [
                'penugasan_id' => $penugasan->id, 'peran' => $penugasan->peran->kode, 'alasan_batal' => $alasan,
            ]);

            return $penugasan;
        });
    }

    /**
     * Pengecualian langsung, atau pengajuan persetujuan pemilik data (1.2d) untuk `tambah` izin sensitif.
     *
     * @param  array{efek: string, sasaran_jenis: string, sasaran_kode: string, berlaku_sampai?: string|null, dasar_pemberian: string, alasan: string, permintaan_akses_id?: string|null}  $isi
     */
    public function buatIzinKhusus(Pengguna $penyunting, Pengguna $akun, array $isi): PenggunaIzinKhusus|PersetujuanAkses
    {
        $this->penjaga->pastikanBolehMengubahAkun($penyunting, $akun);
        [$dari, $sampai] = $this->masaBerlaku($isi);

        [$menuId, $izin] = $isi['sasaran_jenis'] === 'menu'
            ? [Menu::query()->where('kode', $isi['sasaran_kode'])->whereIn('tingkat', ['sub_menu', 'bagian'])->value('id'), null]
            : [null, Izin::query()->where('kode', $isi['sasaran_kode'])->first()];
        if ($menuId === null && $izin === null) {
            throw new ApiError(422, 'sasaran_tidak_dikenal', 'Sasaran tidak dikenal.', [
                ['kolom' => 'sasaran_kode', 'pesan' => 'Kode sub-menu, bagian, atau izin tidak ditemukan.'],
            ]);
        }

        $sensitif = $izin !== null && $izin->sensitif;
        if ($sensitif && $isi['efek'] === 'tambah' && $sampai === null) {
            throw new ApiError(422, 'berlaku_sampai_wajib', 'Pemberian izin sensitif wajib berbatas waktu.', [
                ['kolom' => 'berlaku_sampai', 'pesan' => 'Isi tanggal berakhir untuk izin sensitif.'],
            ]);
        }
        $butuhPersetujuan = $sensitif && $isi['efek'] === 'tambah';
        $penyetuju = $butuhPersetujuan ? $this->alur->penyetujuIzin(collect([$izin])) : [];

        [$kolomSasaran, $idSasaran] = $izin !== null ? ['izin_id', $izin->id] : ['menu_id', $menuId];

        return DB::transaction(function () use ($penyunting, $akun, $isi, $dari, $sampai, $menuId, $izin, $kolomSasaran, $idSasaran, $penyetuju) {
            Pengguna::query()->whereKey($akun->id)->lockForUpdate()->first();
            $ganda = PenggunaIzinKhusus::query()->where('pengguna_id', $akun->id)->where('efek', $isi['efek'])
                ->where($kolomSasaran, $idSasaran);
            if ($ganda->masihAktif()->exists()) {
                throw new ApiError(409, 'pengecualian_ganda', 'Pengecualian yang sama untuk akun ini masih aktif.');
            }
            $this->permintaan->tindaklanjuti($isi['permintaan_akses_id'] ?? null, $akun, $penyunting);

            if ($penyetuju !== [] && $izin !== null) {
                if (PersetujuanAkses::query()->menunggu()->where('objek_jenis', 'izin_khusus')->where('sasaran_pengguna_id', $akun->id)
                    ->where('izin_id', $izin->id)->exists()) {
                    throw $this->persetujuanGanda();
                }

                return $this->alur->ajukan($penyunting, 'pengecualian', 'izin_khusus', [
                    'pengguna_id' => $akun->id, 'izin_id' => $izin->id, 'efek' => $isi['efek'],
                    'berlaku_dari' => $dari->toIso8601String(), 'berlaku_sampai' => $sampai?->toIso8601String(),
                    'dasar_pemberian' => $isi['dasar_pemberian'], 'alasan' => $isi['alasan'],
                ], array_values($penyetuju), ['izin_id' => $izin->id, 'sasaran_pengguna_id' => $akun->id], $isi['dasar_pemberian'], $isi['alasan'])[0];
            }

            $khusus = new PenggunaIzinKhusus([
                'pengguna_id' => $akun->id, 'menu_id' => $menuId, 'izin_id' => $izin?->id, 'efek' => $isi['efek'],
                'berlaku_dari' => $dari, 'berlaku_sampai' => $sampai,
                'dasar_pemberian' => $isi['dasar_pemberian'], 'alasan' => $isi['alasan'], 'diberikan_oleh' => $penyunting->id,
            ]);
            $khusus->forceFill(['status' => 'aktif', 'dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id])->save();

            $this->audit->catat('akses.izin_khusus_dibuat', $penyunting->id, 'akses.pengguna', $akun->id, [
                'izin_khusus_id' => $khusus->id, 'efek' => $isi['efek'], 'sasaran_jenis' => $isi['sasaran_jenis'],
                'sasaran_kode' => $isi['sasaran_kode'], ...$this->isiPemberian($khusus),
            ]);

            return $khusus;
        });
    }

    public function batalkanIzinKhusus(Pengguna $penyunting, Pengguna $akun, PenggunaIzinKhusus $khusus, string $alasan): PenggunaIzinKhusus
    {
        $this->penjaga->pastikanBolehMengubahAkun($penyunting, $akun);

        return DB::transaction(function () use ($penyunting, $akun, $khusus, $alasan) {
            $khusus = PenggunaIzinKhusus::query()->whereKey($khusus->id)->lockForUpdate()->firstOrFail();
            $this->batalkan($khusus, $penyunting, $alasan);
            $this->audit->catat('akses.izin_khusus_dibatalkan', $penyunting->id, 'akses.pengguna', $akun->id, [
                'izin_khusus_id' => $khusus->id, 'efek' => $khusus->efek, 'alasan_batal' => $alasan,
            ]);

            return $khusus;
        });
    }

    /** Sakelar tingkat akun; kewajiban dari peran, izin sensitif, dan akses luar jaringan RS tetap berlaku. */
    public function aturWajibMfa(Pengguna $penyunting, Pengguna $akun, bool $wajib, string $alasan): Pengguna
    {
        $this->penjaga->pastikanBolehMengubahAkun($penyunting, $akun);

        return DB::transaction(function () use ($penyunting, $akun, $wajib, $alasan) {
            $akun = Pengguna::query()->whereKey($akun->id)->lockForUpdate()->firstOrFail();
            $sebelum = $akun->wajib_mfa;
            $akun->forceFill(['wajib_mfa' => $wajib, 'diubah_oleh' => $penyunting->id]);
            // Akun tanpa MFA harus mendaftar dari jaringan RS sebelum memakai sesi lagi.
            $sesiDiputus = $wajib && ! $sebelum && ! $akun->mfaAktif();
            $akun->save();
            if ($sesiDiputus) {
                $akun->cabutSesi();
            }

            $this->audit->catat('akses.wajib_mfa_diubah', $penyunting->id, 'akses.pengguna', $akun->id,
                ['wajib_mfa' => $wajib, 'sesi_diputus' => $sesiDiputus, 'alasan' => $alasan], ['wajib_mfa' => $sebelum]);

            return $akun;
        });
    }

    /** Autentikator hilang/ganti ponsel: rahasia dan kode cadangan dibatalkan, semua sesi akun diputus. */
    public function resetMfa(Pengguna $penyunting, Pengguna $akun, string $alasan, string $dasar): Pengguna
    {
        $this->penjaga->pastikanBolehMengubahAkun($penyunting, $akun);

        return DB::transaction(function () use ($penyunting, $akun, $alasan, $dasar) {
            $akun = Pengguna::query()->whereKey($akun->id)->lockForUpdate()->firstOrFail();
            if (! $akun->mfaAktif()) {
                throw new ApiError(409, 'mfa_belum_aktif', 'Akun ini belum mendaftarkan MFA.');
            }

            $sekarang = now();
            $akun->forceFill([
                'mfa_rahasia_enkripsi' => null, 'mfa_aktif_pada' => null, 'mfa_langkah_terakhir' => null,
                'diubah_oleh' => $penyunting->id,
            ])->save();
            $akun->cabutSesi();
            DB::table('akses.kode_cadangan_mfa')->where('pengguna_id', $akun->id)
                ->whereNull('dipakai_pada')->whereNull('dibatalkan_pada')->update(['dibatalkan_pada' => $sekarang]);
            // Pencabutan perangkat (catat() di dalamnya) dan catat() penutup di akhir: tak ada DML bisnis di antaranya,
            // jadi kunci rantai audit tidak dipegang selama UPDATE (ADR 0003, CLAUDE.md).
            $this->perangkat->cabutSemua($akun->id, 'mfa_direset', $penyunting->id);
            // Tanpa rahasia TOTP atau kode cadangan di log (PRD 14.2).
            $this->audit->catat('akses.mfa_direset', $penyunting->id, 'akses.pengguna', $akun->id, [
                'alasan' => $alasan, 'dasar' => $dasar, 'sesi_diputus' => true,
            ]);

            return $akun;
        });
    }

    /** Nonaktifkan akun (alasan wajib); sesinya diputus. Akun pemegang superadmin hanya lewat K4 (tiket 1.8d). */
    public function nonaktifkan(Pengguna $penyunting, Pengguna $akun, string $alasan): Pengguna
    {
        return $this->ubahStatus($penyunting, $akun, 'nonaktif', $alasan);
    }

    /** Aktifkan kembali akun; ditolak bila pegawainya sudah keluar atau akunnya memegang superadmin (tiket 1.8d). */
    public function aktifkan(Pengguna $penyunting, Pengguna $akun, string $alasan): Pengguna
    {
        return $this->ubahStatus($penyunting, $akun, 'aktif', $alasan);
    }

    private function ubahStatus(Pengguna $penyunting, Pengguna $akun, string $status, string $alasan): Pengguna
    {
        $this->penjaga->pastikanBolehMengubahAkun($penyunting, $akun);

        return DB::transaction(function () use ($penyunting, $akun, $status, $alasan) {
            $akun = Pengguna::query()->whereKey($akun->id)->lockForUpdate()->firstOrFail();
            if ($akun->status === $status) {
                throw new ApiError(409, $status === 'aktif' ? 'sudah_aktif' : 'sudah_nonaktif', 'Akun ini sudah '.$status.'.');
            }
            // Menonaktifkan atau mengaktifkan superadmin = mengubah jumlah superadmin tanpa persetujuan superadmin lain (K4).
            if ($this->penjaga->superadmin($akun, termasukMasaDepan: true)) {
                throw new ApiError(409, 'superadmin_aktif', 'Akun ini memegang peran superadmin. Akhiri penugasan superadminnya lebih dulu lewat persetujuan superadmin lain.');
            }
            if ($status === 'aktif') {
                $pegawai = Pegawai::query()->find($akun->pegawai_id);
                if ($pegawai === null || $pegawai->status !== 'aktif' || $pegawai->statusKepegawaian() === 'keluar') {
                    throw new ApiError(409, 'pegawai_keluar', 'Pegawai pemilik akun ini sudah keluar. Batalkan dulu tanggal keluarnya di data pegawai.');
                }
            }

            $akun->forceFill(['status' => $status, 'diubah_oleh' => $penyunting->id])->save();
            if ($status === 'nonaktif') {
                $akun->cabutSesi();
                $this->perangkat->cabutSemua($akun->id, 'akun_nonaktif', $penyunting->id);
            }
            $this->audit->catat($status === 'aktif' ? 'akses.akun_diaktifkan' : 'akses.akun_dinonaktifkan', $penyunting->id, 'akses.pengguna', $akun->id,
                ['alasan' => $alasan, 'sesi_diputus' => $status === 'nonaktif']);

            return $akun;
        });
    }

    private function persetujuanGanda(): ApiError
    {
        return new ApiError(409, 'persetujuan_ganda', 'Pengajuan yang sama masih menunggu persetujuan.');
    }

    /** Cakupan yang didukung: seluruh RS, atau unit aktif (tiket 1.8a). @return string|null cakupan_id */
    private function pastikanCakupan(string $jenis, ?string $id): ?string
    {
        if (! in_array($jenis, ['rs', 'unit'], true)) {
            throw new ApiError(422, 'cakupan_belum_didukung', 'Cakupan ini belum didukung. Pilih seluruh RS atau sebuah unit.', [
                ['kolom' => 'cakupan_jenis', 'pesan' => 'Hanya cakupan seluruh RS atau unit yang didukung.'],
            ]);
        }
        if (($jenis === 'rs') !== ($id === null) || ($jenis === 'unit' && ! $this->dataUnit->aktif((string) $id))) {
            throw new ApiError(422, 'cakupan_tidak_valid', 'Cakupan tidak valid.', [
                ['kolom' => 'cakupan_id', 'pesan' => $jenis === 'rs' ? 'Seluruh RS tidak memakai unit.' : 'Pilih unit yang ada dan aktif.'],
            ]);
        }

        return $id;
    }

    /**
     * @param  array{berlaku_dari?: string|null, berlaku_sampai?: string|null}  $isi
     * @return array{0: Carbon, 1: Carbon|null}
     */
    private function masaBerlaku(array $isi): array
    {
        $dari = isset($isi['berlaku_dari']) ? Carbon::parse($isi['berlaku_dari']) : now();
        $sampai = isset($isi['berlaku_sampai']) ? Carbon::parse($isi['berlaku_sampai']) : null;
        if ($sampai !== null && ($sampai->lessThanOrEqualTo($dari) || $sampai->isPast())) {
            throw new ApiError(422, 'validasi_gagal', 'Isian belum benar.', [
                ['kolom' => 'berlaku_sampai', 'pesan' => 'Tanggal berakhir harus sesudah tanggal mulai dan belum lewat.'],
            ]);
        }

        return [$dari, $sampai];
    }

    private function batalkan(PemberianAkses $pemberian, Pengguna $penyunting, string $alasan): void
    {
        if ($pemberian->status === 'batal') {
            throw new ApiError(409, 'sudah_batal', 'Sudah diakhiri sebelumnya.');
        }
        $pemberian->forceFill([
            'status' => 'batal', 'alasan_batal' => $alasan, 'dibatalkan_oleh' => $penyunting->id,
            'dibatalkan_pada' => now(), 'diubah_oleh' => $penyunting->id,
        ])->save();
    }

    /** @return array<string, mixed> */
    private function isiPemberian(PemberianAkses $p): array
    {
        return [
            'cakupan_jenis' => $p->cakupan_jenis,
            'berlaku_dari' => $p->berlaku_dari->toIso8601String(),
            'berlaku_sampai' => $p->berlaku_sampai?->toIso8601String(),
            'dasar_pemberian' => $p->dasar_pemberian,
            'alasan' => $p->alasan,
        ];
    }
}
