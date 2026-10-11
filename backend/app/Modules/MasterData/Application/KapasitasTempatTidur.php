<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Events\KapasitasTempatTidurBerubah;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kapasitas TT per tanggal, bangsal, dan kelas (FR-08-18). TT dihitung pada T bila berlaku_dari ≤ T ≤ berlaku_sampai.
 * Rekaman hari ini diperbarui setiap perubahan TT dan dibuat tugas harian; sesudah harinya lewat, rekaman tidak
 * berubah lagi sehingga perubahan TT di tengah bulan tidak mengubah angka hari sebelumnya.
 */
final class KapasitasTempatTidur
{
    /**
     * @param  list<string>  $bangsalId
     * @return array<string, array<string, array{tersedia: int, ekstra: int}>> bangsal id => kelas id => jumlah
     */
    public function hitung(string $tanggal, array $bangsalId): array
    {
        if ($bangsalId === []) {
            return [];
        }
        $baris = DB::table('inti.tempat_tidur AS tt')->join('inti.kamar AS k', 'k.id', '=', 'tt.kamar_id')
            ->whereIn('k.bangsal_id', $bangsalId)->where('tt.berlaku_dari', '<=', $tanggal)
            ->where(fn ($q) => $q->whereNull('tt.berlaku_sampai')->orWhere('tt.berlaku_sampai', '>=', $tanggal))
            ->groupBy('k.bangsal_id', 'tt.kelas_rawat_id')
            ->selectRaw('k.bangsal_id, tt.kelas_rawat_id, count(*) FILTER (WHERE NOT tt.ekstra) AS tersedia, count(*) FILTER (WHERE tt.ekstra) AS ekstra')
            ->get();
        $hasil = [];
        foreach ($baris as $b) {
            $hasil[$b->bangsal_id][$b->kelas_rawat_id] = ['tersedia' => (int) $b->tersedia, 'ekstra' => (int) $b->ekstra];
        }

        return $hasil;
    }

    /**
     * Rekaman tersimpan untuk tanggal itu; null bila tanggal itu belum pernah direkam.
     *
     * @param  list<string>  $bangsalId
     * @return array<string, array<string, array{tersedia: int, ekstra: int}>>|null
     */
    public function tersimpan(string $tanggal, array $bangsalId): ?array
    {
        if (! DB::table('inti.kapasitas_tempat_tidur_harian')->where('tanggal', $tanggal)->exists()) {
            return null;
        }
        $hasil = [];
        foreach (DB::table('inti.kapasitas_tempat_tidur_harian')->where('tanggal', $tanggal)->whereIn('bangsal_id', $bangsalId)
            ->where(fn ($q) => $q->where('jumlah_tersedia', '>', 0)->orWhere('jumlah_ekstra', '>', 0))->get() as $b) {
            $hasil[$b->bangsal_id][$b->kelas_rawat_id] = ['tersedia' => (int) $b->jumlah_tersedia, 'ekstra' => (int) $b->jumlah_ekstra];
        }

        return $hasil;
    }

    /** Simpan rekaman satu tanggal (semua bangsal, atau satu bangsal); kelas yang turun ke nol ikut ditulis nol. */
    public function rekam(string $tanggal, ?string $bangsalId = null): int
    {
        // Berurutan: dua perubahan TT yang hampir bersamaan tidak saling menimpa rekaman dengan angka basi.
        return DB::transaction(function () use ($tanggal, $bangsalId) {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('inti.kapasitas_tempat_tidur_harian'))");

            return $this->tulisRekaman($tanggal, $bangsalId);
        });
    }

    private function tulisRekaman(string $tanggal, ?string $bangsalId): int
    {
        $bangsal = $bangsalId !== null ? [$bangsalId] : DB::table('inti.bangsal')->pluck('id')->all();
        $hitung = $this->hitung($tanggal, $bangsal);
        $lama = DB::table('inti.kapasitas_tempat_tidur_harian')->where('tanggal', $tanggal)->whereIn('bangsal_id', $bangsal)
            ->get(['bangsal_id', 'kelas_rawat_id']);
        foreach ($lama as $l) {
            $hitung[$l->bangsal_id][$l->kelas_rawat_id] ??= ['tersedia' => 0, 'ekstra' => 0];
        }
        $baris = [];
        foreach ($hitung as $b => $perKelas) {
            foreach ($perKelas as $k => $j) {
                $baris[] = ['id' => (string) Str::uuid7(), 'tanggal' => $tanggal, 'bangsal_id' => $b, 'kelas_rawat_id' => $k,
                    'jumlah_tersedia' => $j['tersedia'], 'jumlah_ekstra' => $j['ekstra'], 'diubah_pada' => now()];
            }
        }
        if ($baris !== []) {
            DB::table('inti.kapasitas_tempat_tidur_harian')->upsert($baris, ['tanggal', 'bangsal_id', 'kelas_rawat_id'],
                ['jumlah_tersedia', 'jumlah_ekstra', 'diubah_pada']);
        }

        return count($baris);
    }

    /** Perubahan TT yang berlaku hari ini atau sebelumnya memperbarui rekaman hari ini bangsal itu. */
    public function tanganiPerubahan(KapasitasTempatTidurBerubah $kejadian): void
    {
        $hariIni = SuntinganBerversi::hariIni();
        if ($kejadian->mulaiTanggal <= $hariIni && DB::table('inti.kapasitas_tempat_tidur_harian')->where('tanggal', $hariIni)->exists()) {
            $this->rekam($hariIni, $kejadian->bangsalId);
        }
    }
}
