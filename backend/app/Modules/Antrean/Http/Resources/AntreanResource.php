<?php

namespace App\Modules\Antrean\Http\Resources;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Antrean\Application\KelolaAntrean;
use App\Modules\Antrean\Models\Antrean;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\Pendaftaran\Models\Kunjungan;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bentuk kontrak `Antrean` (tiket 2.8) untuk layar petugas. Ringkasan pasien hanya dua penanda identitas (5.1 butir 9);
 * NIK dan alamat tidak dikirim. Relasi dimuat per halaman, bukan per baris.
 */
final class AntreanResource
{
    public function __construct(private readonly RingkasanPegawai $pegawai) {}

    /** @return array<string, mixed> */
    public function satu(Antrean $antrean): array
    {
        return $this->banyak(collect([$antrean]))[0];
    }

    /**
     * @param  Collection<int, Antrean>  $baris
     * @return list<array<string, mixed>>
     */
    public function banyak(Collection $baris): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $aturan = KelolaAntrean::aturan();
        $pasien = Kunjungan::query()->with('pasien')->whereIn('id', $baris->pluck('kunjungan_id')->filter()->unique())->get()
            ->mapWithKeys(fn (Kunjungan $k) => [$k->id => $k->pasien]);
        $poli = Poliklinik::query()->whereIn('id', $baris->pluck('poli_id')->filter()->unique())->get(['id', 'kode', 'nama'])->keyBy('id');
        $dokter = $this->pegawai->ringkas($baris->pluck('pegawai_id')->filter());
        $jejak = DB::table('kunjungan.antrean_jejak')->whereIn('antrean_id', $baris->pluck('id'))
            ->orderBy('terjadi_pada')->orderBy('id')->get()->groupBy('antrean_id');
        $ruang = Lokasi::query()->whereIn('id', $baris->pluck('ruang_id')->filter()->unique())->get(['id', 'kode_lengkap', 'nama'])->keyBy('id');
        $akun = Pengguna::query()->whereIn('id', $jejak->flatten(1)->pluck('oleh')->merge($baris->pluck('dibatalkan_oleh'))->filter()->unique())
            ->get(['id', 'pegawai_id', 'nama_pengguna']);
        $namaPegawai = $this->pegawai->nama($akun->pluck('pegawai_id')->filter());
        $pelaku = $akun->mapWithKeys(fn (Pengguna $a) => [$a->id => ['id' => $a->id, 'nama' => $namaPegawai[$a->pegawai_id] ?? $a->nama_pengguna]]);

        return $baris->map(function (Antrean $a) use ($aturan, $pasien, $poli, $dokter, $jejak, $ruang, $pelaku) {
            $p = $a->kunjungan_id === null ? null : $pasien[$a->kunjungan_id];
            $r = $a->ruang_id === null ? null : $ruang[$a->ruang_id];

            return [
                'id' => $a->id,
                'tanggal' => $a->tanggal->toDateString(),
                'titik' => $a->titik,
                'area' => $a->area,
                'nomor' => $a->nomor,
                'nomor_tampil' => $a->nomor_tampil,
                'kunjungan_id' => $a->kunjungan_id,
                'pasien' => $p === null ? null : ['id' => $p->id, 'no_rm' => $p->no_rm, 'nama' => $p->nama,
                    'jenis_kelamin' => $p->jenis_kelamin, 'tanggal_lahir' => $p->tanggal_lahir?->toDateString()],
                'poliklinik' => $a->poli_id === null ? null : ['id' => $a->poli_id, 'kode' => $poli[$a->poli_id]->kode, 'nama' => $poli[$a->poli_id]->nama],
                'dokter' => $a->pegawai_id === null ? null : ($dokter[$a->pegawai_id] ?? null),
                'lokasi_panggil' => $r === null ? null : ['id' => $r->id, 'kode_lengkap' => $r->kode_lengkap, 'nama' => $r->nama],
                'sumber' => $a->sumber,
                'prioritas' => $a->prioritas,
                'alasan_prioritas' => $a->alasan_prioritas,
                'status' => $a->status,
                'panggilan_ke' => $a->panggilan_ke,
                'panggilan_maks' => $aturan->batas_panggilan,
                'boleh_panggil_pada' => KelolaAntrean::bolehPanggilPada($a, $aturan->jeda_panggilan_detik)?->toIso8601String(),
                'jejak' => ($jejak[$a->id] ?? collect())->map(fn ($j) => [
                    'status' => $j->status,
                    'terjadi_pada' => Carbon::parse($j->terjadi_pada)->toIso8601String(),
                    'oleh' => $j->oleh === null ? null : ($pelaku[$j->oleh] ?? null),
                    ...($j->panggilan_ke === null ? [] : ['panggilan_ke' => (int) $j->panggilan_ke]),
                ])->values()->all(),
                'alasan_batal' => $a->alasan_batal,
                'dibatalkan_oleh' => $a->dibatalkan_oleh === null ? null : ($pelaku[$a->dibatalkan_oleh] ?? null),
                'dibatalkan_pada' => $a->dibatalkan_pada?->toIso8601String(),
                'dibuat_pada' => $a->dibuat_pada->toIso8601String(),
                'diubah_pada' => $a->diubah_pada->toIso8601String(),
            ];
        })->values()->all();
    }
}
