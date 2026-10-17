<?php

namespace App\Modules\Akses\Http\Resources;

use App\Modules\Akses\Application\KelolaPerangkat;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PerangkatTepercaya;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Collection;

/** PerangkatTepercaya (kontrak v0.16.0). Token dan hash tidak pernah dikirim; pelaku dimuat sekaligus per halaman. */
final class PerangkatPresenter
{
    public function __construct(
        private readonly KelolaPerangkat $kelola,
        private readonly RingkasanPegawai $pegawai,
    ) {}

    /**
     * @param  Collection<int, PerangkatTepercaya>  $baris
     * @param  string|null  $perangkatSesi  id perangkat yang terikat ke sesi ini (hanya /saya/perangkat)
     * @return list<array<string, mixed>>
     */
    public function daftar(Collection $baris, Pengguna $pemilik, ?string $perangkatSesi): array
    {
        $akun = Pengguna::query()->whereIn('id', $baris->pluck('dicabut_oleh')->filter()->unique()->values())->get(['id', 'pegawai_id', 'nama_pengguna']);
        $nama = $this->pegawai->nama($akun->pluck('pegawai_id'));
        $pelaku = $akun->mapWithKeys(fn (Pengguna $a) => [$a->id => ['id' => $a->id, 'nama' => $nama[$a->pegawai_id] ?? $a->nama_pengguna]])->all();
        // Masa percaya efektif sama untuk semua perangkat pemilik ini: dihitung sekali (hindari N+1).
        $hariEfektif = $this->kelola->hariEfektif($pemilik);

        return $baris->map(fn (PerangkatTepercaya $p) => [
            'id' => $p->id,
            'nama_perangkat' => $p->nama_perangkat,
            'status' => $this->kelola->status($p, $pemilik, $hariEfektif),
            'dibuat_pada' => $p->dibuat_pada->toIso8601String(),
            'terakhir_dipakai_pada' => $p->terakhir_dipakai_pada?->toIso8601String(),
            'berlaku_sampai' => $this->kelola->berlakuSampai($p, $pemilik, $hariEfektif)->toIso8601String(),
            'perangkat_ini' => $perangkatSesi !== null && $perangkatSesi === $p->id,
            'dicabut_pada' => $p->dicabut_pada?->toIso8601String(),
            'alasan_cabut' => $p->alasan_cabut,
            'keterangan_cabut' => $p->keterangan_cabut,
            'dicabut_oleh' => $p->dicabut_oleh === null ? null : ($pelaku[$p->dicabut_oleh] ?? null),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function satu(PerangkatTepercaya $perangkat, Pengguna $pemilik, ?string $perangkatSesi): array
    {
        return $this->daftar(collect([$perangkat]), $pemilik, $perangkatSesi)[0];
    }
}
