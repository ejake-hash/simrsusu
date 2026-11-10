<?php

namespace App\Modules\RawatInap\Application;

use App\Core\Http\SuntinganBerversi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Sdm\Application\RingkasanPegawai;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bentuk kontrak `RawatInap` (v0.37.0, tiket 2.29): kunjungan rawat inap dengan episode, kelas dirawat, dan penempatan
 * aktif. Respons POST tempatkan dan pindah ruang. Data pasien = dua penanda identitas; tanpa serah terima klinis.
 */
final class BacaRawatInap
{
    public function __construct(private readonly RingkasanPegawai $pegawai) {}

    /** @return array<string, mixed>|null */
    public function satu(string $kunjunganId): ?array
    {
        $b = DB::table('kunjungan.rawat_inap as ri')
            ->join('kunjungan.kunjungan as k', 'k.id', '=', 'ri.kunjungan_id')
            ->join('pasien.pasien as p', 'p.id', '=', 'k.pasien_id')
            ->join('kunjungan.episode as e', 'e.id', '=', 'k.episode_id')
            ->join('inti.kelas_rawat as kr', 'kr.id', '=', 'ri.kelas_dirawat_id')
            ->where('ri.kunjungan_id', $kunjunganId)
            ->first([
                'k.id as kunjungan_id', 'k.nomor', 'k.status', 'ri.permintaan_tempat_tidur_id', 'ri.asal', 'ri.mulai_pada',
                'p.id as pasien_id', 'p.no_rm', 'p.nama', 'p.jenis_kelamin', 'p.tanggal_lahir',
                'e.id as episode_id', 'e.jenis as episode_jenis', 'e.mulai_tanggal',
                'kr.id as kelas_id', 'kr.kode as kelas_kode', 'kr.nama as kelas_nama',
            ]);
        if ($b === null) {
            return null;
        }
        $pn = DB::table('kunjungan.penempatan_tempat_tidur as pn')
            ->join('inti.tempat_tidur as tt', 'tt.id', '=', 'pn.tempat_tidur_id')
            ->join('inti.kamar as km', 'km.id', '=', 'tt.kamar_id')
            ->join('inti.bangsal as bg', 'bg.id', '=', 'km.bangsal_id')
            ->where('pn.kunjungan_id', $kunjunganId)->whereNull('pn.selesai_pada')
            ->first(['pn.id', 'pn.mulai_pada', 'pn.ditempatkan_oleh', 'tt.id as tt_id', 'tt.kode as tt_kode', 'bg.id as bangsal_id', 'bg.kode as bangsal_kode', 'bg.nama as bangsal_nama']);
        $pertama = DB::table('kunjungan.penempatan_tempat_tidur')->where('kunjungan_id', $kunjunganId)->min('mulai_pada');

        return [
            'kunjungan' => ['id' => (string) $b->kunjungan_id, 'nomor' => (string) $b->nomor, 'status' => (string) $b->status],
            'pasien' => ['id' => (string) $b->pasien_id, 'no_rm' => (string) $b->no_rm, 'nama' => (string) $b->nama, 'jenis_kelamin' => $b->jenis_kelamin,
                'tanggal_lahir' => $b->tanggal_lahir === null ? null : substr((string) $b->tanggal_lahir, 0, 10)],
            'episode' => ['id' => (string) $b->episode_id, 'jenis' => (string) $b->episode_jenis, 'mulai_tanggal' => substr((string) $b->mulai_tanggal, 0, 10)],
            'permintaan_tempat_tidur_id' => (string) $b->permintaan_tempat_tidur_id,
            'asal' => (string) $b->asal,
            'kelas_dirawat' => ['id' => (string) $b->kelas_id, 'kode' => (string) $b->kelas_kode, 'nama' => (string) $b->kelas_nama],
            'mulai_pada' => Carbon::parse((string) $b->mulai_pada)->toIso8601String(),
            'penempatan' => $pn === null ? null : [
                'id' => (string) $pn->id,
                'tempat_tidur' => ['id' => (string) $pn->tt_id, 'kode' => (string) $pn->tt_kode,
                    'bangsal' => ['id' => (string) $pn->bangsal_id, 'kode' => (string) $pn->bangsal_kode, 'nama' => (string) $pn->bangsal_nama]],
                'mulai_pada' => Carbon::parse((string) $pn->mulai_pada)->toIso8601String(),
                'ditempatkan_oleh' => $this->pelaku((string) $pn->ditempatkan_oleh),
            ],
            'hari_rawat' => $pertama === null ? null : self::hariRawat((string) $pertama),
        ];
    }

    /**
     * Hari rawat (FR-08-01): tanggal hari ini zona RS − tanggal penempatan PERTAMA kunjungan + 1, jadi pindah ruang tidak
     * mengulang hitungan.
     */
    public static function hariRawat(string $mulaiPertama): int
    {
        $zona = (string) config('instalasi.zona_waktu');
        $mulai = Carbon::parse($mulaiPertama)->setTimezone($zona)->startOfDay();
        $hariIni = Carbon::parse(SuntinganBerversi::hariIni(), $zona)->startOfDay();

        return max(1, (int) $mulai->diffInDays($hariIni) + 1);
    }

    /** @return array{id: string, nama: string}|null */
    private function pelaku(string $penggunaId): ?array
    {
        $akun = Pengguna::query()->find($penggunaId, ['id', 'pegawai_id', 'nama_pengguna']);
        if ($akun === null) {
            return null;
        }
        $nama = $this->pegawai->nama(array_filter([(string) $akun->pegawai_id]));

        return ['id' => $akun->id, 'nama' => $nama[(string) $akun->pegawai_id] ?? $akun->nama_pengguna];
    }
}
