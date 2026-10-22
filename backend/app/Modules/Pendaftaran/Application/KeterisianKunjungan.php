<?php

namespace App\Modules\Pendaftaran\Application;

use Illuminate\Support\Facades\DB;

/** Jumlah kunjungan tidak batal per jadwal per cara bayar pada satu tanggal (kuota FR-01-15; dipakai juga jadwal 2.4). */
final class KeterisianKunjungan
{
    /**
     * @param  list<string>  $jadwalIds
     * @return array<string, array{jkn: int, non_jkn: int}>
     */
    public function terisi(array $jadwalIds, string $tanggal): array
    {
        if ($jadwalIds === []) {
            return [];
        }
        $hasil = [];
        foreach (DB::table('kunjungan.kunjungan')->whereIn('jadwal_praktik_id', $jadwalIds)->where('tanggal', $tanggal)
            ->where('status', '<>', 'batal')->groupBy('jadwal_praktik_id', 'cara_bayar')
            ->selectRaw('jadwal_praktik_id, cara_bayar, count(*) AS jumlah')->get() as $b) {
            $hasil[$b->jadwal_praktik_id] ??= ['jkn' => 0, 'non_jkn' => 0];
            $hasil[$b->jadwal_praktik_id][$b->cara_bayar] = (int) $b->jumlah;
        }

        return $hasil;
    }

    /**
     * Keterisian tertinggi per cara bayar pada tanggal >= $sejak (cek kuota_di_bawah_terisi saat kuota jadwal diturunkan).
     *
     * @return array{jkn: int, non_jkn: int}
     */
    public function puncakSejak(string $jadwalId, string $sejak): array
    {
        $baris = DB::table('kunjungan.kunjungan')->where('jadwal_praktik_id', $jadwalId)->where('tanggal', '>=', $sejak)
            ->where('status', '<>', 'batal')->groupBy('tanggal', 'cara_bayar')
            ->selectRaw('cara_bayar, count(*) AS jumlah')->get();

        return [
            'jkn' => (int) $baris->where('cara_bayar', 'jkn')->max('jumlah'),
            'non_jkn' => (int) $baris->where('cara_bayar', 'non_jkn')->max('jumlah'),
        ];
    }
}
