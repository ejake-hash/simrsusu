<?php

namespace App\Modules\Akses\Http\Resources;

use App\Modules\Akses\Models\LabelSensitivitas;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaLabel;
use App\Modules\Akses\Models\Peran;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/** Bentuk LabelSensitivitas dan IzinLabelAkun (kontrak v0.14.0, tiket 1.5). Relasi dimuat sekaligus per halaman. */
final class LabelPresenter
{
    public function __construct(private readonly RingkasanPegawai $pegawai) {}

    /**
     * @param  Collection<int, LabelSensitivitas>  $baris
     * @return list<array<string, mixed>>
     */
    public function label(Collection $baris): array
    {
        $peran = Peran::query()->whereIn('id', $baris->pluck('peran_penyetuju_id')->filter()->unique()->values())->get(['id', 'kode', 'nama'])->keyBy('id');

        return $baris->map(fn (LabelSensitivitas $l) => [
            'id' => $l->id,
            'kode' => $l->kode,
            'nama' => $l->nama,
            'uraian' => $l->uraian,
            'butuh_relasi_perawatan' => $l->butuh_relasi_perawatan,
            'penyetuju_peran' => ($r = $peran->get($l->peran_penyetuju_id ?? '')) === null ? null : ['id' => $r->id, 'kode' => $r->kode, 'nama' => $r->nama],
            'aktif' => $l->aktif,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, PenggunaLabel>  $baris
     * @return list<array<string, mixed>>
     */
    public function izin(Collection $baris): array
    {
        $baris = (new EloquentCollection($baris->all()))->loadMissing(['label:id,kode,nama', 'persetujuan:id,nomor,status,kedaluwarsa_pada']);
        $akun = Pengguna::query()->whereIn('id', $baris->flatMap(fn (PenggunaLabel $i) => [$i->diberikan_oleh, $i->disetujui_oleh, $i->dibatalkan_oleh])
            ->filter()->unique()->values())->get(['id', 'pegawai_id', 'nama_pengguna'])->keyBy('id');
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id'));
        $pelaku = fn (?string $id) => ($a = $id === null ? null : $akun->get($id)) === null ? null
            : ['id' => $a->id, 'nama' => $nama[$a->pegawai_id] ?? $a->nama_pengguna];

        return $baris->map(function (PenggunaLabel $i) use ($pelaku) {
            $status = $i->statusTampil();

            return [
                'id' => $i->id,
                'label' => ['kode' => $i->label->kode, 'nama' => $i->label->nama],
                'berlaku_dari' => $i->berlaku_dari?->toIso8601String(),
                'berlaku_sampai' => $i->berlaku_sampai->toIso8601String(),
                'status' => $status,
                'berlaku_sekarang' => $status === 'berlaku',
                'persetujuan' => ['id' => $i->persetujuan->id, 'nomor' => $i->persetujuan->nomor],
                'dasar_pemberian' => $i->dasar_pemberian,
                'alasan' => $i->alasan,
                'diberikan_oleh' => $pelaku($i->diberikan_oleh),
                'disetujui_oleh' => $pelaku($i->disetujui_oleh),
                'alasan_batal' => $i->alasan_batal,
                'dibatalkan_oleh' => $pelaku($i->dibatalkan_oleh),
                'dibatalkan_pada' => $i->dibatalkan_pada?->toIso8601String(),
                'dibuat_pada' => $i->dibuat_pada->toIso8601String(),
            ];
        })->values()->all();
    }
}
