<?php

namespace App\Modules\Sdm\Application;

use App\Modules\Sdm\Models\IzinPraktik;
use App\Modules\Sdm\Models\KewenanganKlinis;

/**
 * Bahan lapis 5 hak akses (tiket 1.5; PRD 9.2.2, 9.2.6): SIP/SPK dan RKK yang berlaku pada tanggal itu (zona RS).
 * Dihitung saat diminta, tidak disimpan di cache: izin yang habis kemarin langsung mengunci aksi klinis hari ini.
 */
final class KewenanganPraktik
{
    /** Jenis izin praktik yang memberi kewenangan klinis (PRD 9.2.2: SIP/SPK). */
    public const JENIS_PRAKTIK = ['sip', 'spk'];

    public function izinPraktikBerlaku(string $pegawaiId): bool
    {
        $hariIni = $this->hariIni();

        return IzinPraktik::query()->where('pegawai_id', $pegawaiId)->where('status', 'aktif')
            ->whereIn('jenis', self::JENIS_PRAKTIK)->where('terbit', '<=', $hariIni)
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $hariIni))
            ->exists();
    }

    /** RKK aktif yang berlaku hari ini memuat kode tindakan ini. */
    public function rkkMemuat(string $pegawaiId, string $kodeTindakan): bool
    {
        $hariIni = $this->hariIni();

        return KewenanganKlinis::query()->where('pegawai_id', $pegawaiId)->where('status', 'aktif')
            ->where('berlaku_dari', '<=', $hariIni)->where('berlaku_sampai', '>=', $hariIni)
            ->whereJsonContains('kode_tindakan', $kodeTindakan)
            ->exists();
    }

    private function hariIni(): string
    {
        return now((string) config('instalasi.zona_waktu'))->toDateString();
    }
}
