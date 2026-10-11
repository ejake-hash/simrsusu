<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PersetujuanAkses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persetujuan pemberian akses berisiko (tiket 1.2d; PRD 9.2.4; K3, K4, K13). Pengajuan = satu grup baris, satu per
 * peran penyetuju; diterapkan dalam transaksi keputusan terakhir (PenerapPersetujuan). Penyetuju memegang peran
 * penyetuju yang berlaku, bukan pengaju, bukan penerima akses. Kedaluwarsa 7 hari.
 */
final class AlurPersetujuan
{
    public const MASA_HARI = 7;

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly PenerapPersetujuan $penerap,
        private readonly KebutuhanPersetujuan $kebutuhan,
    ) {}

    /**
     * @param  Collection<int, Izin>  $izin
     * @return array<string, string> izin id => peran penyetuju id
     */
    public function penyetujuIzin(Collection $izin): array
    {
        return $this->kebutuhan->penyetujuIzin($izin);
    }

    public function peranSuperadmin(): string
    {
        return $this->kebutuhan->peranSuperadmin();
    }

    /** @return list<string> */
    public function penyetujuPenugasan(Peran $peran): array
    {
        return $this->kebutuhan->penugasan($peran);
    }

    /**
     * Buat satu pengajuan (grup) dengan satu baris per peran penyetuju. Dipanggil di dalam transaksi pemanggil.
     *
     * @param  array<string, mixed>  $objek  isi yang diterapkan bila disetujui
     * @param  list<string>  $penyetujuPeranId
     * @param  array{peran_id?: string|null, izin_id?: string|null, sasaran_pengguna_id?: string|null}  $kunci
     * @return list<PersetujuanAkses>
     */
    public function ajukan(Pengguna $pengaju, string $jenis, string $objekJenis, array $objek, array $penyetujuPeranId, array $kunci, string $dasar, ?string $alasan): array
    {
        $grup = (string) Str::uuid7();
        $kedaluwarsa = now()->addDays(self::MASA_HARI);
        $baris = [];
        foreach (array_values(array_unique($penyetujuPeranId)) as $peranId) {
            $p = new PersetujuanAkses([
                'nomor' => $this->nomor(), 'grup_id' => $grup, 'jenis' => $jenis, 'objek_jenis' => $objekJenis, 'objek' => $objek,
                'peran_id' => $kunci['peran_id'] ?? null, 'izin_id' => $kunci['izin_id'] ?? null, 'sasaran_pengguna_id' => $kunci['sasaran_pengguna_id'] ?? null,
                'penyetuju_peran_id' => $peranId, 'status' => 'menunggu', 'diajukan_oleh' => $pengaju->id,
                'alasan' => $alasan, 'dasar' => $dasar, 'kedaluwarsa_pada' => $kedaluwarsa,
                'dibuat_oleh' => $pengaju->id, 'diubah_oleh' => $pengaju->id,
            ]);
            $p->save();
            $baris[] = $p;
        }
        $this->audit->catat('akses.persetujuan_diajukan', $pengaju->id, 'akses.persetujuan_akses', $baris[0]->id, [
            'grup_id' => $grup, 'nomor' => array_map(fn (PersetujuanAkses $p) => $p->nomor, $baris), 'jenis' => $jenis,
            'objek_jenis' => $objekJenis, 'dasar' => $dasar,
        ]);

        return $baris;
    }

    /** Pengajuan yang penerimanya = semua pemegang peran_id (bukan satu akun sasaran). */
    private const OBJEK_PERAN = ['izin_peran', 'penyetuju_izin'];

    /**
     * Peran yang dipegang akun: `berlaku` (penugasan berlaku, peran aktif) menentukan penyetuju; `masih_aktif`
     * (termasuk yang mulai berlaku nanti) menentukan penerima, agar penerima masa depan pun tidak bisa memutuskan.
     *
     * @return array{berlaku: list<string>, masih_aktif: list<string>}
     */
    public function peranDipegang(Pengguna $pengguna): array
    {
        return [
            'berlaku' => PenggunaPeran::query()->berlaku()->where('pengguna_id', $pengguna->id)
                ->whereHas('peran', fn ($q) => $q->where('status', 'aktif'))->pluck('peran_id')->unique()->values()->all(),
            'masih_aktif' => PenggunaPeran::query()->masihAktif()->where('pengguna_id', $pengguna->id)
                ->pluck('peran_id')->unique()->values()->all(),
        ];
    }

    /**
     * Kode penolakan bila akun ini tidak boleh memutuskan (null = boleh). Status/kedaluwarsa diperiksa terpisah.
     * Penerima: akun sasaran (penugasan, pengakhiran, izin khusus); untuk izin peran semua pemegang peran sasaran;
     * untuk penyetuju izin semua pemegang peran yang ditunjuk (kontrak v0.4.0).
     *
     * @param  array{berlaku: list<string>, masih_aktif: list<string>}|null  $dipegang  hasil peranDipegang()
     */
    public function alasanTidakBoleh(Pengguna $pengguna, PersetujuanAkses $p, ?array $dipegang = null): ?string
    {
        $dipegang ??= $this->peranDipegang($pengguna);

        return match (true) {
            ! in_array($p->penyetuju_peran_id, $dipegang['berlaku'], true) => 'bukan_penyetuju',
            $p->diajukan_oleh === $pengguna->id => 'pengaju_sendiri',
            $p->sasaran_pengguna_id === $pengguna->id,
            in_array($p->objek_jenis, self::OBJEK_PERAN, true) && in_array($p->peran_id, $dipegang['masih_aktif'], true) => 'penerima_sendiri',
            default => null,
        };
    }

    /**
     * Pengajuan yang boleh diputuskan akun ini sekarang (tab perlu_saya_putuskan); syarat sama dengan alasanTidakBoleh.
     *
     * @param  array{berlaku: list<string>, masih_aktif: list<string>}  $dipegang
     * @return Builder<PersetujuanAkses>
     */
    public function perluDiputuskan(Pengguna $pengguna, array $dipegang): Builder
    {
        return PersetujuanAkses::query()->menunggu()
            ->whereIn('penyetuju_peran_id', $dipegang['berlaku'])
            ->where('diajukan_oleh', '!=', $pengguna->id)
            ->where(fn ($q) => $q->whereNull('sasaran_pengguna_id')->orWhere('sasaran_pengguna_id', '!=', $pengguna->id))
            ->whereNot(fn ($q) => $q->whereIn('objek_jenis', self::OBJEK_PERAN)->whereIn('peran_id', $dipegang['masih_aktif']));
    }

    public function setujui(Pengguna $penyetuju, PersetujuanAkses $p, ?string $catatan): PersetujuanAkses
    {
        try {
            return DB::transaction(function () use ($penyetuju, $p, $catatan) {
                $grup = $this->kunciGrup($p);
                $p = $grup->firstWhere('id', $p->id);
                $this->pastikanBolehMemutuskan($penyetuju, $p);

                $p->forceFill(['status' => 'disetujui', 'diputuskan_oleh' => $penyetuju->id, 'diputuskan_pada' => now(),
                    'catatan_keputusan' => $catatan, 'diubah_oleh' => $penyetuju->id])->save();
                if ($grup->every(fn (PersetujuanAkses $b) => $b->status === 'disetujui')) {
                    $this->penerap->terapkan($p, $penyetuju);
                    PersetujuanAkses::query()->where('grup_id', $p->grup_id)->update(['diterapkan_pada' => now()]);
                    $p->refresh();
                }
                $this->audit->catat('akses.persetujuan_disetujui', $penyetuju->id, 'akses.persetujuan_akses', $p->id, [
                    'nomor' => $p->nomor, 'diterapkan' => $p->diterapkan_pada !== null, 'catatan' => $catatan,
                ]);

                return $p;
            });
        } catch (TidakBisaDiterapkan $e) {
            DB::transaction(function () use ($p, $e, $penyetuju) {
                $this->kunciGrup($p);
                $this->tutupGrup($p, 'ditolak', null, 'Tidak bisa diterapkan: '.$e->getMessage());
                $this->audit->catat('akses.persetujuan_tidak_bisa_diterapkan', $penyetuju->id, 'akses.persetujuan_akses', $p->id, [
                    'nomor' => $p->nomor, 'alasan' => $e->getMessage(),
                ]);
            });
            throw new ApiError(409, 'tidak_bisa_diterapkan', 'Pengajuan ini tidak bisa diterapkan lagi: '.$e->getMessage());
        }
    }

    public function tolak(Pengguna $penyetuju, PersetujuanAkses $p, string $alasan): PersetujuanAkses
    {
        return DB::transaction(function () use ($penyetuju, $p, $alasan) {
            $p = $this->kunciGrup($p)->firstWhere('id', $p->id);
            $this->pastikanBolehMemutuskan($penyetuju, $p);
            $this->tutupGrup($p, 'ditolak', $penyetuju->id, $alasan);
            $this->audit->catat('akses.persetujuan_ditolak', $penyetuju->id, 'akses.persetujuan_akses', $p->id, ['nomor' => $p->nomor, 'alasan' => $alasan]);

            return $p->refresh();
        });
    }

    /** Hanya pengaju; akun lain mendapat 404 seperti pengajuan yang tidak ada (kontrak). */
    public function batalkan(Pengguna $pengaju, PersetujuanAkses $p, string $alasan): PersetujuanAkses
    {
        return DB::transaction(function () use ($pengaju, $p, $alasan) {
            $p = $this->kunciGrup($p)->firstWhere('id', $p->id);
            if ($p->diajukan_oleh !== $pengaju->id) {
                throw new ApiError(404, 'tidak_ditemukan', 'Data tidak ditemukan.');
            }
            $this->pastikanMenunggu($p);
            $this->tutupGrup($p, 'dibatalkan', $pengaju->id, $alasan);
            $this->audit->catat('akses.persetujuan_dibatalkan', $pengaju->id, 'akses.persetujuan_akses', $p->id, ['nomor' => $p->nomor, 'alasan' => $alasan]);

            return $p->refresh();
        });
    }

    /**
     * Tarik pengajuan yang sasarannya dibatalkan penyunting (mis. izin dikeluarkan dari matriks peran). Dipanggil di
     * dalam transaksi pemanggil; audit ikut audit perubahan pemanggil.
     */
    public function tarik(Pengguna $penyunting, PersetujuanAkses $p, string $catatan): void
    {
        $this->kunciGrup($p);
        $this->tutupGrup($p, 'dibatalkan', $penyunting->id, $catatan);
    }

    /** Tandai kedaluwarsa semua pengajuan yang lewat batas waktu (tugas harian, aman diulang). */
    public function kedaluwarsakan(): int
    {
        return DB::transaction(function () {
            $grup = PersetujuanAkses::query()->where('status', 'menunggu')
                ->where('kedaluwarsa_pada', '<=', now()->format('Y-m-d H:i:s.uP'))->distinct()->pluck('grup_id');
            $jumlah = $grup->isEmpty() ? 0 : PersetujuanAkses::query()->whereIn('grup_id', $grup)->where('status', 'menunggu')
                ->update(['status' => 'kedaluwarsa', 'diputuskan_pada' => now(), 'catatan_keputusan' => 'Kedaluwarsa tanpa keputusan ('.self::MASA_HARI.' hari)']);
            if ($jumlah > 0) {
                $this->audit->catat('akses.persetujuan_kedaluwarsa', null, 'akses.persetujuan_akses', null, ['grup_id' => $grup->all(), 'jumlah' => $jumlah]);
            }

            return $jumlah;
        });
    }

    private function pastikanBolehMemutuskan(Pengguna $pengguna, PersetujuanAkses $p): void
    {
        $this->pastikanMenunggu($p);
        $alasan = $this->alasanTidakBoleh($pengguna, $p);
        if ($alasan !== null) {
            throw new ApiError(403, $alasan, match ($alasan) {
                'pengaju_sendiri' => 'Pengaju tidak bisa memutuskan pengajuannya sendiri.',
                'penerima_sendiri' => 'Penerima akses tidak bisa memutuskan pengajuan untuk dirinya sendiri.',
                default => 'Anda bukan penyetuju pengajuan ini.',
            });
        }
    }

    private function pastikanMenunggu(PersetujuanAkses $p): void
    {
        // Lewat batas waktu: ditolak walau tugas harian belum menandainya (status disimpan oleh tugas harian).
        if ($p->status !== 'menunggu' || ! $p->kedaluwarsa_pada->isFuture()) {
            throw new ApiError(409, 'sudah_diputuskan', 'Pengajuan ini sudah diputuskan, kedaluwarsa, atau dibatalkan.');
        }
    }

    /** @return Collection<int, PersetujuanAkses> */
    private function kunciGrup(PersetujuanAkses $p): Collection
    {
        return PersetujuanAkses::query()->where('grup_id', $p->grup_id)->orderBy('id')->lockForUpdate()->get()->toBase();
    }

    private function tutupGrup(PersetujuanAkses $p, string $status, ?string $oleh, string $catatan): void
    {
        PersetujuanAkses::query()->where('grup_id', $p->grup_id)->where('status', 'menunggu')->update([
            'status' => $status, 'diputuskan_oleh' => $oleh, 'diputuskan_pada' => now(), 'catatan_keputusan' => $catatan, 'diubah_oleh' => $oleh,
        ]);
    }

    private function nomor(): string
    {
        $urut = (int) DB::selectOne("SELECT nextval('akses.persetujuan_akses_nomor_seq') AS n")->n;

        return sprintf('PSA-%s-%06d', now(config('instalasi.zona_waktu'))->format('Y'), $urut);
    }
}
