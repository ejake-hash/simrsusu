<?php

namespace App\Modules\MasterData\Http\Resources;

use App\Modules\MasterData\Application\SuntinganBerversi;
use App\Modules\MasterData\Models\JadwalPengecualian;
use App\Modules\MasterData\Models\JadwalPraktik;
use App\Modules\MasterData\Models\Lokasi;
use App\Modules\MasterData\Models\Poliklinik;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Support\Collection;

/** Bentuk JadwalPraktik dan JadwalPengecualian (kontrak v0.17.0). Relasi dimuat per halaman, bukan per baris. */
final class JadwalPraktikResource
{
    /**
     * @param  Collection<int, JadwalPraktik>  $jadwal
     * @return list<array<string, mixed>>
     */
    public function jadwal(Collection $jadwal, ?string $tanggal = null): array
    {
        $pegawai = Pegawai::query()->whereIn('id', $jadwal->pluck('pegawai_id')->unique())->get()->keyBy('id');
        $poli = Poliklinik::query()->whereIn('id', $jadwal->pluck('poli_id')->unique())->get()->keyBy('id');
        $ruang = Lokasi::query()->whereIn('id', $jadwal->pluck('ruang_id')->filter()->unique())->get()->keyBy('id');
        $pengecualian = $this->pengecualianAktif($pegawai->keys()->all());

        return $jadwal->map(function (JadwalPraktik $j) use ($pegawai, $poli, $ruang, $pengecualian, $tanggal) {
            $menutup = $pengecualian->filter(fn (JadwalPengecualian $p) => $p->pegawai_id === null || $p->pegawai_id === $j->pegawai_id);

            return [
                'id' => $j->id,
                'pegawai' => $this->pegawai($pegawai[$j->pegawai_id]),
                'poliklinik' => ['id' => $j->poli_id, 'kode' => $poli[$j->poli_id]->kode, 'nama' => $poli[$j->poli_id]->nama],
                'ruang' => $j->ruang_id === null ? null : ['id' => $j->ruang_id, 'kode_lengkap' => $ruang[$j->ruang_id]->kode_lengkap, 'nama' => $ruang[$j->ruang_id]->nama],
                'hari' => $j->hari,
                'jam_mulai' => substr($j->jam_mulai, 0, 5),
                'jam_selesai' => substr($j->jam_selesai, 0, 5),
                'kuota_jkn' => $j->kuota_jkn,
                'kuota_non_jkn' => $j->kuota_non_jkn,
                'berlaku_dari' => $j->berlaku_dari->toDateString(),
                'berlaku_sampai' => $j->berlaku_sampai?->toDateString(),
                'status' => $j->status,
                'pada_tanggal' => $tanggal === null ? null : $this->keterisian($j, $tanggal, $menutup),
                'pengecualian' => $menutup->map(fn (JadwalPengecualian $p) => $this->pengecualian($p, $pegawai[$p->pegawai_id] ?? null))->values()->all(),
                'versi' => (string) $j->revisi,
                'diubah_pada' => $j->diubah_pada->toIso8601String(),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function pengecualian(JadwalPengecualian $p, ?Pegawai $pegawai = null): array
    {
        $pegawai ??= $p->pegawai_id === null ? null : Pegawai::query()->find($p->pegawai_id);

        return [
            'id' => $p->id, 'jenis' => $p->jenis, 'pegawai' => $pegawai === null ? null : $this->pegawai($pegawai),
            'dari_tanggal' => $p->dari_tanggal->toDateString(), 'sampai_tanggal' => $p->sampai_tanggal->toDateString(),
            'alasan' => $p->alasan, 'status' => $p->status, 'alasan_batal' => $p->alasan_batal,
            'versi' => (string) $p->revisi, 'diubah_pada' => $p->diubah_pada->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function pegawai(Pegawai $p): array
    {
        return ['id' => $p->id, 'nama' => $p->nama, 'status' => $p->status];
    }

    /**
     * Terisi selalu 0 sampai tabel kunjungan ada (tiket 2.6).
     *
     * @param  Collection<int, JadwalPengecualian>  $menutup
     * @return array<string, mixed>
     */
    private function keterisian(JadwalPraktik $j, string $tanggal, Collection $menutup): array
    {
        $kena = $menutup->first(fn (JadwalPengecualian $p) => $p->dari_tanggal->toDateString() <= $tanggal && $p->sampai_tanggal->toDateString() >= $tanggal);
        $alasan = $j->status !== 'aktif' ? 'nonaktif' : $kena?->jenis;

        return [
            'tanggal' => $tanggal,
            'kuota_jkn' => $j->kuota_jkn, 'terisi_jkn' => 0, 'sisa_jkn' => $j->kuota_jkn,
            'kuota_non_jkn' => $j->kuota_non_jkn, 'terisi_non_jkn' => 0, 'sisa_non_jkn' => $j->kuota_non_jkn,
            'tersedia' => $alasan === null, 'alasan_tidak_tersedia' => $alasan,
        ];
    }

    /**
     * Pengecualian aktif yang belum lewat: milik dokter di halaman ini atau libur RS.
     *
     * @param  list<string|int>  $pegawaiIds
     * @return Collection<int, JadwalPengecualian>
     */
    private function pengecualianAktif(array $pegawaiIds): Collection
    {
        return JadwalPengecualian::query()->where('status', 'aktif')->where('sampai_tanggal', '>=', SuntinganBerversi::hariIni())
            ->where(fn ($q) => $q->whereNull('pegawai_id')->orWhereIn('pegawai_id', $pegawaiIds))
            ->orderBy('dari_tanggal')->orderBy('id')->get();
    }
}
