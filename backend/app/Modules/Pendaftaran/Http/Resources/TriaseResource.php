<?php

namespace App\Modules\Pendaftaran\Http\Resources;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Bentuk kontrak `Triase` (v0.32.0, tiket 2.24). Jam tiba dari kunjungan (tidak disalin ke baris triase). Kode/nama
 * instrumen dan label skala = salinan saat triase, bukan data induk terkini: riwayat tidak berubah arti bila instrumen
 * diedit sesudahnya.
 */
final class TriaseResource
{
    public function __construct(private readonly RingkasanPegawai $pegawai) {}

    /**
     * @param  Collection<int, mixed>  $baris  baris kunjungan.triase satu kunjungan
     * @return list<array<string, mixed>>
     */
    public function banyak(Collection $baris, ?string $tibaPada, ?string $berlakuId): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $akun = Pengguna::query()->whereIn('id', $baris->pluck('petugas_id')->unique()->values())->get(['id', 'pegawai_id', 'nama_pengguna']);
        $namaPegawai = $this->pegawai->nama($akun->pluck('pegawai_id')->filter());
        $petugas = $akun->mapWithKeys(fn (Pengguna $a) => [$a->id => ['id' => $a->id, 'nama' => $namaPegawai[$a->pegawai_id] ?? $a->nama_pengguna]]);

        return $baris->map(function ($t) use ($petugas, $tibaPada, $berlakuId) {
            return [
                'id' => $t->id,
                'kunjungan_id' => $t->kunjungan_id,
                'kategori' => $t->kategori,
                'instrumen' => $t->instrumen_triase_id === null ? null : ['id' => $t->instrumen_triase_id, 'kode' => $t->instrumen_kode, 'nama' => $t->instrumen_nama],
                'skala' => $t->skala === null ? null : ['kode' => $t->skala, 'label' => $t->skala_label],
                'keluhan' => $t->keluhan,
                'tiba_pada' => $tibaPada,
                'ditriase_pada' => Carbon::parse($t->ditriase_pada)->toIso8601String(),
                'petugas' => $petugas[$t->petugas_id] ?? null,
                'berlaku' => $t->id === $berlakuId,
            ];
        })->values()->all();
    }
}
