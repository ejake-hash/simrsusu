<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;

/** Pintu baca penugasan untuk modul lain (MasterData): siapa memakai cakupan apa (PRD 10.3). */
final class PemakaianCakupan
{
    /** Akun dengan penugasan aktif (sedang atau akan berlaku) ber-cakupan ini. */
    public function jumlahPenugasan(string $jenis, string $id): int
    {
        return PenggunaPeran::query()->masihAktif()->where('cakupan_jenis', $jenis)->where('cakupan_id', $id)
            ->distinct()->count('pengguna_id');
    }

    /**
     * Unit dari penugasan ber-cakupan `unit` yang berlaku sekarang (pemilih unit kerja di header).
     *
     * @return list<string>
     */
    public function unitBerlaku(Pengguna $pengguna): array
    {
        return PenggunaPeran::query()->berlaku()->where('pengguna_id', $pengguna->id)->where('cakupan_jenis', 'unit')
            ->whereHas('peran', fn ($p) => $p->where('status', 'aktif'))
            ->distinct()->pluck('cakupan_id')->filter()->values()->all();
    }
}
