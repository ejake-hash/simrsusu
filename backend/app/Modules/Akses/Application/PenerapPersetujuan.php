<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PersetujuanAkses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menerapkan pengajuan yang seluruh grupnya disetujui, di dalam transaksi keputusan terakhir (tiket 1.2d).
 * Setiap jenis memeriksa ulang keadaan (peran/akun aktif, ganda, superadmin minimal); bila tidak lagi sah,
 * TidakBisaDiterapkan dan pengajuan ditolak sistem.
 */
final class PenerapPersetujuan
{
    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly CacheHakAkses $cache,
        private readonly KebutuhanPersetujuan $kebutuhan,
        private readonly DataUnit $dataUnit,
    ) {}

    public function terapkan(PersetujuanAkses $p, Pengguna $penyetuju): void
    {
        match ($p->objek_jenis) {
            'izin_peran' => $this->izinPeran($p, $penyetuju),
            'penugasan' => $this->penugasan($p, $penyetuju),
            'pengakhiran_penugasan' => $this->pengakhiran($p),
            'izin_khusus' => $this->izinKhusus($p, $penyetuju),
            'penyetuju_izin' => $this->penyetujuIzin($p, $penyetuju),
            default => throw new TidakBisaDiterapkan('Jenis pengajuan tidak dikenal.'),
        };
    }

    private function izinPeran(PersetujuanAkses $p, Pengguna $penyetuju): void
    {
        $peran = Peran::query()->lockForUpdate()->find($p->peran_id);
        $izin = Izin::query()->find($p->izin_id);
        if ($peran === null || $peran->status !== 'aktif' || $izin === null) {
            throw new TidakBisaDiterapkan('Peran sudah nonaktif atau izin tidak ada lagi.');
        }

        $sekarang = now();
        $nilai = ['status' => 'aktif', 'alasan_batal' => null, 'dibatalkan_oleh' => null, 'dibatalkan_pada' => null,
            'disetujui_oleh' => $penyetuju->id, 'disetujui_pada' => $sekarang, 'diubah_oleh' => $p->diajukan_oleh, 'diubah_pada' => $sekarang];
        DB::table('akses.peran_izin')->upsert([['id' => (string) Str::uuid7(), 'peran_id' => $peran->id, 'izin_id' => $izin->id, ...$nilai]],
            ['peran_id', 'izin_id'], array_keys($nilai));
        if ($izin->jenis_aksi === 'lihat') {
            DB::table('akses.peran_menu')->upsert([['id' => (string) Str::uuid7(), 'peran_id' => $peran->id, 'menu_id' => $izin->menu_id,
                'status' => 'aktif', 'alasan_batal' => null, 'dibatalkan_oleh' => null, 'dibatalkan_pada' => null]],
                ['peran_id', 'menu_id'], ['status', 'alasan_batal', 'dibatalkan_oleh', 'dibatalkan_pada']);
        }
        Peran::query()->whereKey($peran->id)->update(['revisi' => DB::raw('revisi + 1')]);
        $this->cache->lupakanSemua();

        $this->audit->catat('akses.izin_peran_disetujui', $penyetuju->id, 'akses.peran', $peran->id, [
            'izin' => $izin->kode, 'persetujuan' => $p->nomor, 'dasar_perubahan' => $p->dasar,
        ]);
    }

    private function penugasan(PersetujuanAkses $p, Pengguna $penyetuju): void
    {
        $o = $p->objek;
        $akun = Pengguna::query()->lockForUpdate()->find($o['pengguna_id']);
        // Dikunci: perubahan izin peran yang bersamaan menunggu sampai penerapan ini selesai.
        $peran = Peran::query()->lockForUpdate()->find($o['peran_id']);
        if ($akun === null || ! $akun->aktif() || $peran === null || $peran->status !== 'aktif') {
            throw new TidakBisaDiterapkan('Akun atau peran sudah tidak aktif.');
        }
        if ($o['cakupan_jenis'] === 'unit' && ! $this->dataUnit->aktif((string) $o['cakupan_id'])) {
            throw new TidakBisaDiterapkan('Unit cakupan sudah nonaktif.');
        }
        // Isi peran bisa berubah selagi menunggu (izin sensitif baru, atau peran dijadikan penyetuju): penyetuju yang
        // dibutuhkan sekarang harus sudah ada dalam grup yang menyetujui.
        try {
            $perlu = $this->kebutuhan->penugasan($peran, kunci: true);
        } catch (ApiError) {
            throw new TidakBisaDiterapkan('Ada izin sensitif di peran ini yang belum punya penyetuju.');
        }
        $grup = PersetujuanAkses::query()->where('grup_id', $p->grup_id)->pluck('penyetuju_peran_id')->all();
        if (array_diff($perlu, $grup) !== []) {
            throw new TidakBisaDiterapkan('Isi peran berubah sejak diajukan dan kini butuh penyetuju lain; ajukan ulang.');
        }
        $ganda = PenggunaPeran::query()->masihAktif()->where('pengguna_id', $akun->id)->where('peran_id', $peran->id)
            ->where('cakupan_jenis', $o['cakupan_jenis'])
            ->when($o['cakupan_id'] ?? null, fn ($q, $id) => $q->where('cakupan_id', $id), fn ($q) => $q->whereNull('cakupan_id'))
            ->exists();
        if ($ganda) {
            throw new TidakBisaDiterapkan('Akun sudah punya penugasan aktif untuk peran dan cakupan yang sama.');
        }

        $penugasan = new PenggunaPeran([
            'pengguna_id' => $akun->id, 'peran_id' => $peran->id, 'cakupan_jenis' => $o['cakupan_jenis'], 'cakupan_id' => $o['cakupan_id'] ?? null,
            'berlaku_dari' => $o['berlaku_dari'], 'berlaku_sampai' => $o['berlaku_sampai'] ?? null,
            'dasar_pemberian' => $o['dasar_pemberian'], 'alasan' => $o['alasan'] ?? null,
            'diberikan_oleh' => $p->diajukan_oleh, 'disetujui_oleh' => $penyetuju->id,
        ]);
        $penugasan->forceFill(['status' => 'aktif', 'dibuat_oleh' => $p->diajukan_oleh, 'diubah_oleh' => $penyetuju->id])->save();

        $this->audit->catat('akses.penugasan_dibuat', $penyetuju->id, 'akses.pengguna', $akun->id, [
            'penugasan_id' => $penugasan->id, 'peran' => $peran->kode, 'persetujuan' => $p->nomor,
            'cakupan_jenis' => $penugasan->cakupan_jenis, 'dasar_pemberian' => $penugasan->dasar_pemberian,
        ]);
    }

    private function pengakhiran(PersetujuanAkses $p): void
    {
        $penugasan = PenggunaPeran::query()->lockForUpdate()->find($p->objek['penugasan_id']);
        if ($penugasan === null || $penugasan->status !== 'aktif') {
            throw new TidakBisaDiterapkan('Penugasan sudah berakhir.');
        }
        // Kunci yang sama dengan SuperadminPertama dan KelolaAksesAkun: dua pengakhiran bersamaan tidak bisa sama-sama lolos.
        DB::select("SELECT pg_advisory_xact_lock(hashtext('akses.superadmin_pertama'))");
        if ($penugasan->peran->kode === MenuBawaan::PERAN_SUPERADMIN && app(SuperadminPertama::class)->jumlahAktif() <= SuperadminPertama::BATAS) {
            throw new TidakBisaDiterapkan('Superadmin aktif tinggal '.SuperadminPertama::BATAS.'; tidak bisa dikurangi (PRD 9.2.4).');
        }

        $penugasan->forceFill([
            'status' => 'batal', 'alasan_batal' => $p->objek['alasan_batal'].' (persetujuan '.$p->nomor.')',
            'dibatalkan_oleh' => $p->diajukan_oleh, 'dibatalkan_pada' => now(), 'diubah_oleh' => $p->diajukan_oleh,
        ])->save();

        $this->audit->catat('akses.penugasan_dibatalkan', $p->diajukan_oleh, 'akses.pengguna', $penugasan->pengguna_id, [
            'penugasan_id' => $penugasan->id, 'peran' => $penugasan->peran->kode, 'persetujuan' => $p->nomor, 'alasan_batal' => $p->objek['alasan_batal'],
        ]);
    }

    private function izinKhusus(PersetujuanAkses $p, Pengguna $penyetuju): void
    {
        $o = $p->objek;
        $akun = Pengguna::query()->lockForUpdate()->find($o['pengguna_id']);
        if ($akun === null || ! $akun->aktif()) {
            throw new TidakBisaDiterapkan('Akun sudah tidak aktif.');
        }
        $ganda = PenggunaIzinKhusus::query()->masihAktif()->where('pengguna_id', $akun->id)->where('efek', $o['efek'])
            ->where('izin_id', $o['izin_id'])->exists();
        if ($ganda) {
            throw new TidakBisaDiterapkan('Pengecualian yang sama sudah aktif.');
        }

        $khusus = new PenggunaIzinKhusus([
            'pengguna_id' => $akun->id, 'izin_id' => $o['izin_id'], 'efek' => $o['efek'],
            'berlaku_dari' => $o['berlaku_dari'], 'berlaku_sampai' => $o['berlaku_sampai'] ?? null,
            'dasar_pemberian' => $o['dasar_pemberian'], 'alasan' => $o['alasan'],
            'diberikan_oleh' => $p->diajukan_oleh, 'disetujui_oleh' => $penyetuju->id,
        ]);
        $khusus->forceFill(['status' => 'aktif', 'dibuat_oleh' => $p->diajukan_oleh, 'diubah_oleh' => $penyetuju->id])->save();

        $this->audit->catat('akses.izin_khusus_dibuat', $penyetuju->id, 'akses.pengguna', $akun->id, [
            'izin_khusus_id' => $khusus->id, 'efek' => $khusus->efek, 'persetujuan' => $p->nomor, 'dasar_pemberian' => $khusus->dasar_pemberian,
        ]);
    }

    private function penyetujuIzin(PersetujuanAkses $p, Pengguna $penyetuju): void
    {
        $izin = Izin::query()->lockForUpdate()->find($p->izin_id);
        $peran = Peran::query()->find($p->peran_id);
        if ($izin === null || ! $izin->sensitif || $peran === null || $peran->status !== 'aktif') {
            throw new TidakBisaDiterapkan('Peran sudah nonaktif atau izin tidak lagi sensitif.');
        }

        $sebelum = $izin->peran_penyetuju_id;
        Izin::query()->whereKey($izin->id)->update(['peran_penyetuju_id' => $peran->id, 'diubah_oleh' => $p->diajukan_oleh, 'diubah_pada' => now()]);

        $this->audit->catat('akses.penyetuju_izin_diubah', $penyetuju->id, 'akses.izin', $izin->id, [
            'kode' => $izin->kode, 'peran_penyetuju_id' => $peran->id, 'persetujuan' => $p->nomor, 'dasar_perubahan' => $p->dasar,
        ], ['peran_penyetuju_id' => $sebelum]);
    }
}
