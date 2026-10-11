<?php

namespace App\Modules\Akses\Http\Resources;

use App\Modules\Akses\Application\AlurPersetujuan;
use App\Modules\Akses\Application\DataUnit;
use App\Modules\Akses\Application\PohonMenu;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PersetujuanAkses;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Collection;

/**
 * Bentuk PersetujuanAkses (kontrak v0.4.0). Peran, akun, izin, label, grup, dan dampak dimuat sekaligus per halaman.
 * Status menunggu yang lewat batas waktu tampil `kedaluwarsa` walau tugas harian belum jalan. `akun_terdampak`
 * dihitung saat dibaca (pemegang peran sekarang), bukan saat diajukan.
 */
final class PersetujuanPresenter
{
    public function __construct(
        private readonly AlurPersetujuan $alur,
        private readonly RingkasanPegawai $pegawai,
        private readonly DataUnit $dataUnit,
    ) {}

    /** @return array<string, mixed> */
    public function satu(PersetujuanAkses $p, Pengguna $penonton): array
    {
        return $this->daftar(collect([$p]), $penonton)[0];
    }

    /**
     * @param  Collection<int, PersetujuanAkses>  $baris
     * @return list<array<string, mixed>>
     */
    public function daftar(Collection $baris, Pengguna $penonton): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $dipegang = $this->alur->peranDipegang($penonton);
        $objekPeran = fn (PersetujuanAkses $p) => in_array($p->objek_jenis, ['izin_peran', 'penyetuju_izin'], true);

        $peranId = $baris->flatMap(fn (PersetujuanAkses $p) => [$p->peran_id, $p->penyetuju_peran_id, $p->objek['peran_penyetuju_sebelum_id'] ?? null]);
        $peran = Peran::query()->whereIn('id', $peranId->filter()->unique()->values())->get(['id', 'kode', 'nama'])->keyBy('id');
        $izin = Izin::query()->whereIn('id', $baris->pluck('izin_id')->filter()->unique()->values())->get(['id', 'kode', 'menu_id', 'jenis_aksi'])->keyBy('id');
        $menu = $izin->isEmpty() ? collect() : Menu::query()->get(['id', 'induk_id', 'tingkat', 'kode', 'label'])->keyBy('id');
        $akun = Pengguna::query()->whereIn('id', $baris->flatMap(fn (PersetujuanAkses $p) => [$p->sasaran_pengguna_id, $p->diajukan_oleh, $p->diputuskan_oleh])
            ->filter()->unique()->values())->get(['id', 'pegawai_id', 'nama_pengguna'])->keyBy('id');
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id'));
        $unit = $this->dataUnit->label($baris->map(fn (PersetujuanAkses $p) => ($p->objek['cakupan_jenis'] ?? null) === 'unit' ? ($p->objek['cakupan_id'] ?? null) : null)
            ->filter()->unique()->values()->all());
        $grup = PersetujuanAkses::query()->whereIn('grup_id', $baris->pluck('grup_id')->unique()->values())
            ->selectRaw("grup_id, count(*) AS jumlah, count(*) FILTER (WHERE status = 'disetujui') AS disetujui")->groupBy('grup_id')->get()->keyBy('grup_id');
        $pemegang = PenggunaPeran::query()->berlaku()->whereIn('peran_id', $baris->filter($objekPeran)->pluck('peran_id')->unique()->values())
            ->selectRaw('peran_id, count(DISTINCT pengguna_id) AS jumlah')->groupBy('peran_id')->pluck('jumlah', 'peran_id');

        $ringkasPeran = fn (?string $id) => ($r = $id === null ? null : $peran->get($id)) === null ? null : ['id' => $r->id, 'kode' => $r->kode, 'nama' => $r->nama];
        $namaAkun = fn (Pengguna $a) => $nama[$a->pegawai_id] ?? $a->nama_pengguna;
        $pelaku = fn (?string $id) => ($a = $id === null ? null : $akun->get($id)) === null ? null : ['id' => $a->id, 'nama' => $namaAkun($a)];
        $labelIzin = fn (Izin $i) => PohonMenu::jalurLabel($menu, $i->menu_id).' → '.PohonMenu::labelAksi($i->jenis_aksi);

        return $baris->map(function (PersetujuanAkses $p) use ($dipegang, $objekPeran, $peran, $izin, $akun, $unit, $grup, $pemegang, $ringkasPeran, $namaAkun, $pelaku, $labelIzin, $penonton) {
            $kedaluwarsa = $p->status === 'menunggu' && ! $p->kedaluwarsa_pada->isFuture();
            $status = $kedaluwarsa ? 'kedaluwarsa' : $p->status;
            $i = $p->izin_id === null ? null : $izin->get($p->izin_id);
            $sasaran = $p->sasaran_pengguna_id === null ? null : $akun->get($p->sasaran_pengguna_id);
            $cakupan = match ($p->objek['cakupan_jenis'] ?? null) {
                'rs' => 'Seluruh RS',
                'unit' => $unit[$p->objek['cakupan_id'] ?? ''] ?? null,
                default => null,
            };
            $objek = [
                'jenis' => $p->objek_jenis,
                'penugasan_id' => $p->objek['penugasan_id'] ?? null,
                'peran' => $ringkasPeran($p->peran_id),
                'akun' => $sasaran === null ? null : ['id' => $sasaran->id, 'nama_pengguna' => $sasaran->nama_pengguna, 'nama' => $namaAkun($sasaran)],
                'izin' => $i === null ? null : ['kode' => $i->kode, 'label' => $labelIzin($i)],
                'cakupan_label' => $cakupan,
                'berlaku_sampai' => $p->objek['berlaku_sampai'] ?? null,
            ];
            $g = $grup->get($p->grup_id);

            return [
                'id' => $p->id,
                'nomor' => $p->nomor,
                'jenis' => $p->jenis,
                'ringkasan' => $this->ringkasan($p, $objek, $peran->get($p->objek['peran_penyetuju_sebelum_id'] ?? '')?->nama),
                'objek' => $objek,
                'penyetuju_peran' => $ringkasPeran($p->penyetuju_peran_id),
                'status' => $status,
                'diterapkan' => $p->diterapkan_pada !== null,
                'diajukan_oleh' => $pelaku($p->diajukan_oleh),
                'diajukan_pada' => $p->dibuat_pada->toIso8601String(),
                'alasan' => $p->alasan,
                'dasar' => $p->dasar,
                'kedaluwarsa_pada' => $p->kedaluwarsa_pada->toIso8601String(),
                'diputuskan_oleh' => $pelaku($p->diputuskan_oleh),
                'diputuskan_pada' => $p->diputuskan_pada?->toIso8601String(),
                'catatan_keputusan' => $p->catatan_keputusan,
                'grup' => ['id' => $p->grup_id, 'jumlah' => (int) ($g->jumlah ?? 1), 'disetujui' => (int) ($g->disetujui ?? 0)],
                'akun_terdampak' => $objekPeran($p) ? (int) ($pemegang[$p->peran_id] ?? 0) : 1,
                'dapat_diputuskan' => $status === 'menunggu' && $this->alur->alasanTidakBoleh($penonton, $p, $dipegang) === null,
            ];
        })->values()->all();
    }

    /** @param  array<string, mixed>  $objek */
    private function ringkasan(PersetujuanAkses $p, array $objek, ?string $penyetujuSebelum): string
    {
        $peran = $objek['peran']['nama'] ?? '-';
        $akun = $objek['akun']['nama'] ?? '-';
        $izin = $objek['izin']['label'] ?? '-';

        return match ($p->objek_jenis) {
            'izin_peran' => "Tambah izin {$izin} ke peran {$peran}",
            'penugasan' => "Tugaskan peran {$peran} ke {$akun}".($objek['cakupan_label'] !== null ? " ({$objek['cakupan_label']})" : ''),
            'pengakhiran_penugasan' => "Akhiri penugasan peran {$peran} untuk {$akun}",
            'izin_khusus' => "Tambah pengecualian {$izin} untuk {$akun}",
            'penyetuju_izin' => "Tetapkan peran {$peran} sebagai penyetuju izin {$izin}".($penyetujuSebelum !== null ? " (sebelumnya {$penyetujuSebelum})" : ''),
            default => $p->nomor,
        };
    }
}
