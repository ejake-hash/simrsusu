<?php

namespace App\Modules\Sdm\Http\Resources;

use App\Core\Audit\CatatAktivitas;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Sdm\Application\KelolaKredensial;
use App\Modules\Sdm\Models\IzinPraktik;
use App\Modules\Sdm\Models\KewenanganKlinis;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Bentuk kontrak v0.6.0 (tiket 1.8d): PegawaiRingkas/PegawaiRinci, IzinPraktik, KewenanganKlinis. Data bagian yang
 * tidak diizinkan tidak dikirim: NIK utuh hanya dengan sdm.pegawai.identitas.lihat (pembacaannya diaudit), isi akun hanya
 * dengan pengaturan.akun.lihat.
 */
final class PegawaiResource
{
    public const IZIN_NIK = 'sdm.pegawai.identitas.lihat';

    public function __construct(private readonly CatatAktivitas $audit) {}

    public static function bolehNik(Pengguna $penonton): bool
    {
        return Gate::forUser($penonton)->allows(self::IZIN_NIK, Cakupan::rs());
    }

    /**
     * @param  Collection<int, Pegawai>  $baris
     * @return list<array<string, mixed>>
     */
    public function ringkas(Collection $baris, Pengguna $penonton): array
    {
        $id = $baris->pluck('id')->all();
        $unit = DB::table('inti.unit_organisasi')->whereIn('id', $baris->pluck('unit_organisasi_id')->filter()->unique()->values())
            ->get(['id', 'kode', 'nama'])->keyBy('id');
        $akun = $id === [] ? collect() : DB::table('akses.pengguna')->whereIn('pegawai_id', $id)->get(['id', 'pegawai_id', 'nama_pengguna', 'status'])->keyBy('pegawai_id');
        $lihatAkun = Gate::forUser($penonton)->allows('pengaturan.akun.lihat', Cakupan::rs());
        $izin = $id === [] ? collect() : IzinPraktik::query()->whereIn('pegawai_id', $id)->where('status', 'aktif')->get()->groupBy('pegawai_id');
        $urutanBuruk = ['habis' => 3, 'akan_habis' => 2, 'berlaku' => 1, 'belum_berlaku' => 0];

        return $baris->map(function (Pegawai $p) use ($unit, $akun, $lihatAkun, $izin, $urutanBuruk) {
            $u = $p->unit_organisasi_id === null ? null : $unit->get($p->unit_organisasi_id);
            $a = $akun->get($p->id);
            // Per jenis dipakai izin terbaru (berlaku paling lama; seumur hidup menang), lalu keadaan terburuk antarjenis:
            // SIP lama yang habis tidak lagi dihitung sesudah SIP pengganti dicatat.
            $status = ($izin[$p->id] ?? collect())->groupBy('jenis')
                ->map(fn ($perJenis) => KelolaKredensial::statusIzin($perJenis->sortByDesc(
                    fn (IzinPraktik $i) => $i->berlaku_sampai?->getTimestamp() ?? PHP_INT_MAX)->first()))
                ->sortByDesc(fn (string $s) => $urutanBuruk[$s] ?? 0)->first();

            return [
                'id' => $p->id,
                'nip' => $p->nip,
                'nama' => $p->nama,
                'nik_samaran' => $p->nikSamaran(),
                'profesi' => $p->profesi,
                'jabatan' => $p->jabatan,
                'unit' => $u === null ? null : ['id' => $u->id, 'kode' => $u->kode, 'nama' => $u->nama],
                'status' => $p->statusKepegawaian(),
                'punya_akun' => $a !== null,
                'akun' => $a === null || ! $lihatAkun ? null : ['id' => $a->id, 'nama_pengguna' => $a->nama_pengguna, 'status' => $a->status],
                'izin_praktik_status' => $status,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> NIK utuh hanya bila $tampilkanNik (GET ?tampilkan_nik=true; izin diperiksa pemanggil) */
    public function rinci(Pegawai $p, Pengguna $penonton, bool $tampilkanNik = false): array
    {
        $bolehNik = self::bolehNik($penonton);
        $data = [
            ...$this->ringkas(collect([$p]), $penonton)[0],
            'nik_dapat_dilihat' => $bolehNik,
            'jenis_kepegawaian' => $p->jenis_kepegawaian,
            'tmt' => $p->tmt?->toDateString(),
            'tanggal_keluar' => $p->tanggal_keluar?->toDateString(),
            'alasan_keluar' => $p->alasan_keluar,
            'ihs_practitioner_id' => $p->ihs_practitioner_id,
            'versi' => (string) $p->revisi,
            'diubah_pada' => $p->diubah_pada->toIso8601String(),
        ];
        if ($tampilkanNik && $bolehNik && $p->nik() !== null) {
            $data['nik'] = $p->nik();
            $this->audit->catat('sdm.nik_dilihat', $penonton->id, 'sdm.pegawai', $p->id, []);
        }

        return $data;
    }

    /**
     * @param  Collection<int, IzinPraktik>  $baris
     * @return list<array<string, mixed>>
     */
    public function izinPraktik(Collection $baris): array
    {
        $pelaku = $this->pelaku($baris->flatMap(fn (IzinPraktik $i) => [$i->dibuat_oleh, $i->dibatalkan_oleh]));

        return $baris->map(fn (IzinPraktik $i) => [
            'id' => $i->id,
            'jenis' => $i->jenis,
            'nomor' => $i->nomor,
            'terbit' => $i->terbit->toDateString(),
            'berlaku_sampai' => $i->berlaku_sampai?->toDateString(),
            'status' => KelolaKredensial::statusIzin($i),
            'dokumen' => $i->dokumen_jalur === null ? null : ['jenis_mime' => $i->dokumen_mime, 'ukuran' => $i->dokumen_ukuran],
            'dicatat_oleh' => $pelaku[$i->dibuat_oleh ?? ''] ?? null,
            'dicatat_pada' => $i->dibuat_pada->toIso8601String(),
            'alasan_batal' => $i->alasan_batal,
            'dibatalkan_oleh' => $pelaku[$i->dibatalkan_oleh ?? ''] ?? null,
            'dibatalkan_pada' => $i->dibatalkan_pada?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, KewenanganKlinis>  $baris
     * @return list<array<string, mixed>>
     */
    public function kewenangan(Collection $baris): array
    {
        $pelaku = $this->pelaku($baris->flatMap(fn (KewenanganKlinis $k) => [$k->dibuat_oleh, $k->dibatalkan_oleh]));

        return $baris->map(fn (KewenanganKlinis $k) => [
            'id' => $k->id,
            'kategori' => $k->kategori,
            'kode_tindakan' => $k->kode_tindakan,
            'berlaku_dari' => $k->berlaku_dari->toDateString(),
            'berlaku_sampai' => $k->berlaku_sampai->toDateString(),
            'komite' => $k->komite,
            'dasar' => $k->dasar,
            'status' => KelolaKredensial::statusKewenangan($k),
            'dicatat_oleh' => $pelaku[$k->dibuat_oleh ?? ''] ?? null,
            'dicatat_pada' => $k->dibuat_pada->toIso8601String(),
            'alasan_batal' => $k->alasan_batal,
            'dibatalkan_oleh' => $pelaku[$k->dibatalkan_oleh ?? ''] ?? null,
            'dibatalkan_pada' => $k->dibatalkan_pada?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, string|null>  $penggunaId
     * @return array<string, array{id: string, nama: string}>
     */
    private function pelaku(Collection $penggunaId): array
    {
        return DB::table('akses.pengguna AS a')->leftJoin('sdm.pegawai AS p', 'p.id', '=', 'a.pegawai_id')
            ->whereIn('a.id', $penggunaId->filter()->unique()->values())->get(['a.id', 'a.nama_pengguna', 'p.nama'])
            ->mapWithKeys(fn ($b) => [$b->id => ['id' => $b->id, 'nama' => $b->nama ?? $b->nama_pengguna]])->all();
    }
}
