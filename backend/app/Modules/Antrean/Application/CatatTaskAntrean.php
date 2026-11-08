<?php

namespace App\Modules\Antrean\Application;

use App\Modules\Antrean\Events\AntreanBerubah;
use App\Modules\Antrean\Models\Antrean;
use App\Modules\Pendaftaran\Events\KunjunganDibuat;
use App\Modules\RekamMedis\Events\AsesmenPerawatSelesai;
use App\Modules\RekamMedis\Events\PemeriksaanDimulai;
use App\Modules\RekamMedis\Events\PemeriksaanSelesai;
use App\Modules\RekamMedis\Events\ResepDisahkan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Jam task ID Antrean BPJS 1-7 dari kejadian sistem (tiket 2.18; PRD 7.02.1). Nomor task dibaca dari
 * kunjungan.pemetaan_task_antrean (data), kejadian tanpa baris aktif diabaikan. Hanya kunjungan JKN; idempoten per antrean + task_no.
 */
final class CatatTaskAntrean
{
    // shortcut: baris task hanya ditandai 'tertahan', belum ditulis ke integrasi.kotak_keluar (tujuan 'antrean' mengubah kontrak integrasi); 2.19 membaca status_kirim.
    public function catat(string $kejadian, ?Antrean $antrean, string $terjadiPada): void
    {
        if ($antrean === null) {
            return;
        }
        $task = DB::table('kunjungan.pemetaan_task_antrean')->where('kejadian', $kejadian)->where('aktif', true)->value('task_no');
        if ($task === null || DB::table('kunjungan.kunjungan')->where('id', $antrean->kunjungan_id)->value('cara_bayar') !== 'jkn') {
            return;
        }
        DB::table('kunjungan.antrean_task')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'antrean_id' => $antrean->id, 'kunjungan_id' => $antrean->kunjungan_id, 'task_no' => $task,
            'kejadian' => $kejadian, 'terjadi_pada' => $terjadiPada,
        ]);
    }

    private static function poli(string $kunjunganId): ?Antrean
    {
        return Antrean::query()->where('kunjungan_id', $kunjunganId)->where('titik', 'poli')->first();
    }

    public function kunjunganDibuat(KunjunganDibuat $e): void
    {
        $this->catat('kunjungan_dibuat', self::poli($e->kunjunganId), now()->toIso8601String());
    }

    public function asesmenPerawatSelesai(AsesmenPerawatSelesai $e): void
    {
        $this->catat('asesmen_perawat_selesai', self::poli($e->kunjunganId), $e->terjadiPada);
    }

    public function pemeriksaanDimulai(PemeriksaanDimulai $e): void
    {
        $this->catat('pemeriksaan_dimulai', self::poli($e->kunjunganId), $e->terjadiPada);
    }

    public function pemeriksaanSelesai(PemeriksaanSelesai $e): void
    {
        $this->catat('pemeriksaan_selesai', self::poli($e->kunjunganId), $e->terjadiPada);
    }

    public function resepDisahkan(ResepDisahkan $e): void
    {
        $this->catat('resep_disahkan', KelolaAntrean::antreanFarmasi($e->pasienId, $e->tanggal), $e->terjadiPada);
    }

    public function antreanBerubah(AntreanBerubah $e): void
    {
        $antrean = Antrean::query()->find($e->antreanId);
        $this->catat("antrean.{$antrean?->titik}.{$e->status}", $antrean, $e->terjadiPada);
    }
}
