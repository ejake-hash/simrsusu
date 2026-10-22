<?php

namespace App\Modules\Pendaftaran\Http\Resources;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\Penjamin;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Bentuk kontrak v0.20.0 `Kunjungan` (tiket 2.6). Ringkasan pasien hanya dua penanda identitas (nama + No. RM/tanggal
 * lahir, 5.1 butir 9); NIK dan alamat tidak dikirim. Relasi dimuat per halaman, bukan per baris.
 */
final class KunjunganResource
{
    public function __construct(private readonly RingkasanPegawai $pegawai) {}

    /** @return array<string, mixed> */
    public function satu(Kunjungan $kunjungan): array
    {
        return $this->banyak(collect([$kunjungan]))[0];
    }

    /**
     * @param  Collection<int, Kunjungan>  $baris
     * @return list<array<string, mixed>>
     */
    public function banyak(Collection $baris): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $baris = (new EloquentCollection($baris->all()))->load(['pasien', 'episode']);
        $poli = Poliklinik::query()->whereIn('id', $baris->pluck('poli_id')->unique())->get(['id', 'kode', 'nama'])->keyBy('id');
        $penjamin = Penjamin::query()->whereIn('id', $baris->pluck('penjamin_id')->filter()->unique())->get(['id', 'kode', 'nama', 'jenis'])->keyBy('id');
        $dokter = $this->pegawai->ringkas($baris->pluck('pegawai_id')->unique());
        $akun = Pengguna::query()->whereIn('id', $baris->pluck('dibuat_oleh')->merge($baris->pluck('dibatalkan_oleh'))->filter()->unique())
            ->get(['id', 'pegawai_id', 'nama_pengguna']);
        $namaPegawai = $this->pegawai->nama($akun->pluck('pegawai_id')->filter());
        $pelaku = $akun->mapWithKeys(fn (Pengguna $a) => [$a->id => ['id' => $a->id, 'nama' => $namaPegawai[$a->pegawai_id] ?? $a->nama_pengguna]]);

        return $baris->map(function (Kunjungan $k) use ($poli, $penjamin, $dokter, $pelaku) {
            $p = $k->pasien;
            $j = $k->penjamin_id === null ? null : $penjamin[$k->penjamin_id];

            return [
                'id' => $k->id,
                'nomor' => $k->nomor,
                'pasien' => ['id' => $p->id, 'no_rm' => $p->no_rm, 'nama' => $p->nama, 'jenis_kelamin' => $p->jenis_kelamin,
                    'tanggal_lahir' => $p->tanggal_lahir?->toDateString()],
                'episode' => ['id' => $k->episode->id, 'jenis' => $k->episode->jenis, 'mulai_tanggal' => $k->episode->mulai_tanggal->toDateString()],
                'jenis_kunjungan' => $k->jenis_kunjungan,
                'poliklinik' => ['id' => $k->poli_id, 'kode' => $poli[$k->poli_id]->kode, 'nama' => $poli[$k->poli_id]->nama],
                'dokter' => $dokter[$k->pegawai_id] ?? null,
                'tanggal' => $k->tanggal->toDateString(),
                'cara_bayar' => $k->cara_bayar,
                'penjamin' => $j === null ? null : ['id' => $j->id, 'kode' => $j->kode, 'nama' => $j->nama, 'jenis' => $j->jenis],
                'status' => $k->status,
                'keluhan' => $k->keluhan,
                'alasan_batal' => $k->alasan_batal,
                'dibatalkan_oleh' => $k->dibatalkan_oleh === null ? null : ($pelaku[$k->dibatalkan_oleh] ?? null),
                'dibatalkan_pada' => $k->dibatalkan_pada?->toIso8601String(),
                'didaftarkan_oleh' => $k->dibuat_oleh === null ? null : ($pelaku[$k->dibuat_oleh] ?? null),
                'versi' => (string) $k->revisi,
                'dibuat_pada' => $k->dibuat_pada->toIso8601String(),
                'diubah_pada' => $k->diubah_pada->toIso8601String(),
            ];
        })->values()->all();
    }
}
