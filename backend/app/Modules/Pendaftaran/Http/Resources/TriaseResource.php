<?php

namespace App\Modules\Pendaftaran\Http\Resources;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Pendaftaran\Application\KelolaTriase;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bentuk kontrak `Triase` (v0.32.0, tiket 2.24). Jam tiba dari kunjungan (tidak disalin ke baris triase); label skala
 * dari data induk instrumen saat ini (kode skala yang sudah tidak ada di data induk tampil apa adanya).
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
        $instrumen = DB::table('inti.instrumen_triase')->whereIn('id', $baris->pluck('instrumen_triase_id')->filter()->unique()->values())
            ->get(['id', 'kode', 'nama', 'skala'])->keyBy('id');
        $akun = Pengguna::query()->whereIn('id', $baris->pluck('petugas_id')->unique()->values())->get(['id', 'pegawai_id', 'nama_pengguna']);
        $namaPegawai = $this->pegawai->nama($akun->pluck('pegawai_id')->filter());
        $petugas = $akun->mapWithKeys(fn (Pengguna $a) => [$a->id => ['id' => $a->id, 'nama' => $namaPegawai[$a->pegawai_id] ?? $a->nama_pengguna]]);

        return $baris->map(function ($t) use ($instrumen, $petugas, $tibaPada, $berlakuId) {
            $i = $t->instrumen_triase_id === null ? null : ($instrumen[$t->instrumen_triase_id] ?? null);
            $skala = null;
            if ($t->skala !== null) {
                $label = collect(KelolaTriase::daftarSkala($i?->skala))->firstWhere('kode', $t->skala)['label'] ?? $t->skala;
                $skala = ['kode' => $t->skala, 'label' => $label];
            }

            return [
                'id' => $t->id,
                'kunjungan_id' => $t->kunjungan_id,
                'kategori' => $t->kategori,
                'instrumen' => $i === null ? null : ['id' => $i->id, 'kode' => $i->kode, 'nama' => $i->nama],
                'skala' => $skala,
                'keluhan' => $t->keluhan,
                'tiba_pada' => $tibaPada,
                'ditriase_pada' => Carbon::parse($t->ditriase_pada)->toIso8601String(),
                'petugas' => $petugas[$t->petugas_id] ?? null,
                'berlaku' => $t->id === $berlakuId,
            ];
        })->values()->all();
    }
}
