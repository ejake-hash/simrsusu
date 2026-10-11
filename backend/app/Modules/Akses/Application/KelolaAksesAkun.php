<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\PemberianAkses;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan → Akun & akses (tiket 1.2c-be, PRD 9.2.3–9.3, 10.3.2, kontrak /akun/{id}/*).
 *
 * - Penyunting tidak pernah mengubah akses akunnya sendiri (403 diri_sendiri, PRD 9.2.4).
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
    ) {}

    /** @param  array{peran_id: string, cakupan_jenis: string, berlaku_dari?: string|null, berlaku_sampai?: string|null, dasar_pemberian: string, alasan?: string|null}  $isi */
    public function tugaskan(Pengguna $penyunting, Pengguna $akun, array $isi): PenggunaPeran
    {
        $this->penjaga->pastikanBukanDiriSendiri($penyunting, $akun);
        $this->pastikanCakupanRs($isi['cakupan_jenis']);
        [$dari, $sampai] = $this->masaBerlaku($isi);

        return DB::transaction(function () use ($penyunting, $akun, $isi, $dari, $sampai) {
            // Penugasan bersamaan ke akun yang sama tidak boleh sama-sama lolos cek ganda di bawah.
            Pengguna::query()->whereKey($akun->id)->lockForUpdate()->first();
            $peran = Peran::query()->findOrFail($isi['peran_id']);

            if ($peran->bawaan_sistem) {
                throw new ApiError(409, 'peran_bawaan_terkunci', 'Peran superadmin hanya diberikan lewat konsol server sampai persetujuan superadmin lain tersedia.');
            }
            if ($peran->status !== 'aktif') {
                throw new ApiError(409, 'peran_nonaktif', 'Peran ini nonaktif dan tidak bisa ditugaskan.');
            }
            if ($peran->memuatIzinSensitif()) {
                throw new ApiError(409, 'butuh_persetujuan', 'Peran ini memuat izin sensitif; penugasannya butuh persetujuan pemilik data.');
            }
            $ganda = PenggunaPeran::query()->where('pengguna_id', $akun->id)->where('peran_id', $peran->id)
                ->where('cakupan_jenis', $isi['cakupan_jenis'])->whereNull('cakupan_id');
            if ($this->masihAktif($ganda)->exists()) {
                throw new ApiError(409, 'penugasan_ganda', 'Akun ini sudah punya penugasan aktif untuk peran dan cakupan yang sama.');
            }

            $penugasan = new PenggunaPeran([
                'pengguna_id' => $akun->id, 'peran_id' => $peran->id, 'cakupan_jenis' => $isi['cakupan_jenis'],
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

    public function batalkanPenugasan(Pengguna $penyunting, Pengguna $akun, PenggunaPeran $penugasan, string $alasan): PenggunaPeran
    {
        $this->penjaga->pastikanBukanDiriSendiri($penyunting, $akun);

        return DB::transaction(function () use ($penyunting, $akun, $penugasan, $alasan) {
            $penugasan = PenggunaPeran::query()->whereKey($penugasan->id)->lockForUpdate()->firstOrFail();
            if ($penugasan->peran->bawaan_sistem) {
                throw new ApiError(409, 'peran_bawaan_terkunci', 'Penugasan superadmin hanya diakhiri dengan persetujuan superadmin lain.');
            }
            $this->batalkan($penugasan, $penyunting, $alasan);
            $this->audit->catat('akses.penugasan_dibatalkan', $penyunting->id, 'akses.pengguna', $akun->id, [
                'penugasan_id' => $penugasan->id, 'peran' => $penugasan->peran->kode, 'alasan_batal' => $alasan,
            ]);

            return $penugasan;
        });
    }

    /** @param  array{efek: string, sasaran_jenis: string, sasaran_kode: string, berlaku_sampai?: string|null, dasar_pemberian: string, alasan: string}  $isi */
    public function buatIzinKhusus(Pengguna $penyunting, Pengguna $akun, array $isi): PenggunaIzinKhusus
    {
        $this->penjaga->pastikanBukanDiriSendiri($penyunting, $akun);
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
        if ($sensitif && $isi['efek'] === 'tambah') {
            throw new ApiError(409, 'butuh_persetujuan', 'Izin sensitif hanya bisa diberikan dengan persetujuan pemilik data.', [
                ['kode' => $izin->kode, 'pesan' => 'Izin sensitif'],
            ]);
        }

        [$kolomSasaran, $idSasaran] = $izin !== null ? ['izin_id', $izin->id] : ['menu_id', $menuId];

        return DB::transaction(function () use ($penyunting, $akun, $isi, $dari, $sampai, $menuId, $izin, $kolomSasaran, $idSasaran) {
            Pengguna::query()->whereKey($akun->id)->lockForUpdate()->first();
            $ganda = PenggunaIzinKhusus::query()->where('pengguna_id', $akun->id)->where('efek', $isi['efek'])
                ->where($kolomSasaran, $idSasaran);
            if ($this->masihAktif($ganda)->exists()) {
                throw new ApiError(409, 'pengecualian_ganda', 'Pengecualian yang sama untuk akun ini masih aktif.');
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
        $this->penjaga->pastikanBukanDiriSendiri($penyunting, $akun);

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
        $this->penjaga->pastikanBukanDiriSendiri($penyunting, $akun);

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
        $this->penjaga->pastikanBukanDiriSendiri($penyunting, $akun);

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

            // Tanpa rahasia TOTP atau kode cadangan di log (PRD 14.2).
            $this->audit->catat('akses.mfa_direset', $penyunting->id, 'akses.pengguna', $akun->id, [
                'alasan' => $alasan, 'dasar' => $dasar, 'sesi_diputus' => true,
            ]);

            return $akun;
        });
    }

    private function pastikanCakupanRs(string $jenis): void
    {
        if ($jenis !== 'rs') {
            throw new ApiError(422, 'cakupan_belum_didukung', 'Untuk saat ini hanya cakupan seluruh RS. Cakupan lain menunggu data unit.', [
                ['kolom' => 'cakupan_jenis', 'pesan' => 'Hanya cakupan seluruh RS yang didukung.'],
            ]);
        }
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

    /**
     * Status aktif dan belum lewat masanya (sedang atau akan berlaku).
     *
     * @template T of PemberianAkses
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    private function masihAktif($query)
    {
        return $query->where('status', 'aktif')
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>', now()->format('Y-m-d H:i:s.uP')));
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
