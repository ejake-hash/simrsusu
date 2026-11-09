<?php

namespace App\Modules\Pendaftaran\Http\Resources;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Bentuk kontrak `Disposisi` (v0.33.0, tiket 2.26): satu baris kunjungan.perpindahan_pos. Episode = potret saat perintah
 * (jenis dan status sesudah perintah), bukan episode terkini.
 */
final class DisposisiResource
{
    public function __construct(private readonly RingkasanPegawai $pegawai) {}

    /**
     * @param  Collection<int, mixed>  $baris  baris kunjungan.perpindahan_pos
     * @return list<array<string, mixed>>
     */
    public function banyak(Collection $baris): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $akun = Pengguna::query()->whereIn('id', $baris->pluck('diputuskan_oleh')->unique()->values())->get(['id', 'pegawai_id', 'nama_pengguna']);
        $namaPegawai = $this->pegawai->nama($akun->pluck('pegawai_id')->filter());
        $pelaku = $akun->mapWithKeys(fn (Pengguna $a) => [$a->id => ['id' => $a->id, 'nama' => $namaPegawai[$a->pegawai_id] ?? $a->nama_pengguna]]);

        return $baris->map(fn ($p) => [
            'id' => $p->id,
            'kunjungan_id' => $p->dari_kunjungan_id,
            'episode' => ['id' => $p->episode_id, 'jenis' => $p->episode_jenis, 'status' => $p->episode_status],
            'pos_asal' => $p->pos_asal,
            'tujuan' => $p->pos_tujuan,
            'aturan_perpindahan_id' => $p->aturan_perpindahan_id,
            'alasan' => $p->alasan,
            'serah_terima' => $p->serah_terima,
            'status' => $p->status,
            'diputuskan_oleh' => $pelaku[$p->diputuskan_oleh] ?? null,
            'diputuskan_pada' => Carbon::parse($p->terjadi_pada)->toIso8601String(),
        ])->values()->all();
    }
}
