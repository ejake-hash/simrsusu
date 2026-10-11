<?php

namespace App\Modules\Notifikasi\Application;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use Illuminate\Support\Facades\DB;

/**
 * Siapa yang menerima tugas dan eskalasinya (tiket 1.7). Pelaksana: akun itu sendiri, pemegang peran (penugasan
 * berlaku), atau pemegang penugasan bercakupan unit itu. Tingkat 2-3: akun aktif milik kepala unit
 * (inti.unit_organisasi.kepala_pegawai_id, 1.8d).
 */
final class PenerimaTugas
{
    /** @return list<string> id akses.pengguna aktif */
    public function pelaksana(string $jenis, string $id): array
    {
        $akun = match ($jenis) {
            'pengguna' => Pengguna::query()->whereKey($id),
            'peran' => Pengguna::query()->whereIn('id', PenggunaPeran::query()->berlaku()->where('peran_id', $id)->select('pengguna_id')),
            'unit' => Pengguna::query()->whereIn('id', PenggunaPeran::query()->berlaku()->where('cakupan_jenis', 'unit')->where('cakupan_id', $id)->select('pengguna_id')),
            default => Pengguna::query()->whereRaw('false'),
        };

        return $akun->where('status', 'aktif')->pluck('id')->all();
    }

    /** @return list<string> akun aktif kepala unit itu (kosong bila kepala belum ditetapkan atau tanpa akun) */
    public function kepalaUnit(?string $unitId): array
    {
        if ($unitId === null) {
            return [];
        }
        $pegawai = DB::table('inti.unit_organisasi')->where('id', $unitId)->value('kepala_pegawai_id');

        return $pegawai === null ? [] : Pengguna::query()->where('pegawai_id', $pegawai)->where('status', 'aktif')->pluck('id')->all();
    }

    public function induk(?string $unitId): ?string
    {
        return $unitId === null ? null : DB::table('inti.unit_organisasi')->where('id', $unitId)->value('induk_id');
    }

    /**
     * Unit yang dikepalai akun ini beserta semua turunannya (tab unit).
     *
     * @return list<string>
     */
    public function unitDikepalai(Pengguna $pengguna): array
    {
        return array_column(DB::select(
            'WITH RECURSIVE turun AS (
                SELECT id FROM inti.unit_organisasi WHERE kepala_pegawai_id = ?
                UNION
                SELECT u.id FROM inti.unit_organisasi u JOIN turun ON u.induk_id = turun.id
             ) SELECT id FROM turun',
            [$pengguna->pegawai_id],
        ), 'id');
    }

    /**
     * Pelaksana yang "milik" akun ini: akun sendiri, peran yang dipegang, unit penugasannya.
     *
     * @return list<array{0: string, 1: string}> [jenis, id]
     */
    public function identitasPelaksana(Pengguna $pengguna): array
    {
        $penugasan = PenggunaPeran::query()->berlaku()->where('pengguna_id', $pengguna->id)->get(['peran_id', 'cakupan_jenis', 'cakupan_id']);
        $hasil = [['pengguna', $pengguna->id]];
        foreach ($penugasan->pluck('peran_id')->unique() as $peran) {
            $hasil[] = ['peran', (string) $peran];
        }
        foreach ($penugasan->where('cakupan_jenis', 'unit')->pluck('cakupan_id')->filter()->unique() as $unit) {
            $hasil[] = ['unit', (string) $unit];
        }

        return $hasil;
    }
}
