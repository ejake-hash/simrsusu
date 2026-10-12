<?php

namespace App\Modules\MasterData\Application;

use Illuminate\Support\Facades\DB;

/**
 * Data induk yang dibaca modul Integrasi (tiket 1.9). PRD 10.3: modul lain membaca lewat antarmuka modul ini, tidak
 * langsung ke tabel `inti.*`.
 */
final class DataIntegrasi
{
    /**
     * Ruang Aplicares sebuah bangsal pada satu tanggal: satu baris per kelas rawat yang pernah punya TT di bangsal itu.
     * Kelas yang semua TT-nya sudah ditutup tetap muncul dengan kapasitas 0 agar angka di BPJS ikut turun. TT ekstra
     * (lonjakan) tidak dihitung. Jumlah per aturan jenis kelamin TT.
     *
     * @return array{kode: string, nama: string, unit_organisasi_id: string, kelas: list<array{kode: string, nama: string, kode_aplicares: string|null, kapasitas: int, laki_laki: int, perempuan: int, campur: int}>}|null
     */
    public function ruangAplicares(string $bangsalId, string $tanggal): ?array
    {
        $bangsal = DB::table('inti.bangsal')->where('id', $bangsalId)->first(['kode', 'nama', 'unit_organisasi_id']);
        if ($bangsal === null) {
            return null;
        }
        $aktif = 'NOT tt.ekstra AND tt.berlaku_dari <= ?::date AND (tt.berlaku_sampai IS NULL OR tt.berlaku_sampai >= ?::date)';
        $kelas = DB::table('inti.tempat_tidur AS tt')->join('inti.kamar AS k', 'k.id', '=', 'tt.kamar_id')
            ->join('inti.kelas_rawat AS kr', 'kr.id', '=', 'tt.kelas_rawat_id')->where('k.bangsal_id', $bangsalId)
            ->groupBy('kr.id', 'kr.kode', 'kr.nama', 'kr.kode_aplicares')->orderBy('kr.kode')
            ->selectRaw("kr.kode, kr.nama, kr.kode_aplicares, count(*) FILTER (WHERE $aktif) AS kapasitas,
                count(*) FILTER (WHERE $aktif AND tt.aturan_jenis_kelamin = 'laki_laki') AS laki_laki,
                count(*) FILTER (WHERE $aktif AND tt.aturan_jenis_kelamin = 'perempuan') AS perempuan,
                count(*) FILTER (WHERE $aktif AND tt.aturan_jenis_kelamin = 'campur') AS campur", array_fill(0, 8, $tanggal))
            ->get();

        return ['kode' => $bangsal->kode, 'nama' => $bangsal->nama, 'unit_organisasi_id' => $bangsal->unit_organisasi_id,
            'kelas' => $kelas->map(fn (object $k) => ['kode' => $k->kode, 'nama' => $k->nama, 'kode_aplicares' => $k->kode_aplicares,
                'kapasitas' => (int) $k->kapasitas, 'laki_laki' => (int) $k->laki_laki, 'perempuan' => (int) $k->perempuan, 'campur' => (int) $k->campur])->all()];
    }

    /**
     * Bangsal yang pernah punya TT (sinkron Aplicares menyeluruh). Jumlah bangsal satu RS kecil (puluhan).
     *
     * @return list<string>
     */
    public function bangsalBerTempatTidur(): array
    {
        return DB::table('inti.kamar AS k')->whereExists(fn ($q) => $q->from('inti.tempat_tidur AS tt')->whereColumn('tt.kamar_id', 'k.id'))
            ->distinct()->orderBy('k.bangsal_id')->pluck('k.bangsal_id')->all();
    }
}
