<?php

namespace App\Modules\Pendaftaran\Application;

use App\Core\Audit\CatatAktivitas;
use App\Modules\Pendaftaran\Models\Episode;
use App\Modules\Pendaftaran\Models\Kunjungan;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Episode rawat inap (7.00.2 baris "Rawat inap", FR-00-01; tiket 2.29): dibentuk saat pasien ditempatkan di TT. Awal
 * rawat inap = jam SPRI (atau awal tanggal rencana), tanggalnya T (zona RS). Semua episode aktif pasien berjenis rawat
 * jalan harian atau IGD > 6 jam yang memuat kunjungan bertanggal T digabung ke satu episode rawat inap: episode
 * kunjungan asal permintaan dipakai bila termasuk, selain itu yang paling awal; jenisnya diubah, kunjungan dan aktivitas
 * episode lain dipindah ke sana (pola BentukEpisodeIgd), lalu episode yang kosong batal (tidak dihapus). Tanpa episode
 * bertanggal T: episode rawat inap baru. Perintah perpindahan menyimpan potret episode, jadi tidak ikut dipindah.
 * Dipanggil di dalam transaksi pemanggil dengan pasien sudah terkunci (KunciKunjungan).
 */
final class BentukEpisodeRawatInap
{
    public const ALASAN_BATAL_EPISODE = 'Digabung ke episode rawat inap';

    /** Jenis episode yang tergabung ke rawat inap di hari yang sama (7.00.2). */
    public const JENIS_DIGABUNG = [Episode::RAWAT_JALAN_HARIAN, Episode::IGD_LEBIH_6_JAM];

    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @param  string  $tanggal  tanggal awal rawat inap (zona RS) */
    public function bentuk(Kunjungan $asal, string $tanggal, string $olehId): Episode
    {
        $calon = Episode::query()->where('pasien_id', $asal->pasien_id)->where('status', 'aktif')->whereIn('jenis', self::JENIS_DIGABUNG)
            ->whereExists(fn (Builder $q) => $q->from('kunjungan.kunjungan AS kt')->whereColumn('kt.episode_id', 'kunjungan.episode.id')
                ->where('kt.tanggal', $tanggal)->where('kt.status', '<>', 'batal'))
            ->orderBy('mulai_tanggal')->orderBy('id')->lockForUpdate()->get();

        if ($calon->isEmpty()) {
            $baru = new Episode;
            $baru->forceFill(['pasien_id' => $asal->pasien_id, 'jenis' => Episode::RAWAT_INAP, 'mulai_tanggal' => $tanggal,
                'dibuat_oleh' => $olehId, 'diubah_oleh' => $olehId])->save();
            $this->audit->catat('episode.rawat_inap_dibentuk', $olehId, $baru->getTable(), $baru->id,
                ['jenis' => Episode::RAWAT_INAP, 'mulai_tanggal' => $tanggal, 'episode_digabung' => [], 'kunjungan_asal_id' => $asal->id]);

            return $baru;
        }

        /** @var Episode $dasar */
        $dasar = $calon->firstWhere('id', $asal->episode_id) ?? $calon->first();
        $jenisSebelum = $dasar->jenis;
        $dasar->forceFill(['jenis' => Episode::RAWAT_INAP, 'diubah_oleh' => $olehId, 'revisi' => $dasar->revisi + 1])->save();

        $digabung = [];
        foreach ($calon->where('id', '<>', $dasar->id) as $lain) {
            $digabung[$lain->id] = DB::table('kunjungan.kunjungan')->where('episode_id', $lain->id)
                ->update(['episode_id' => $dasar->id, 'diubah_oleh' => $olehId, 'diubah_pada' => now(), 'revisi' => DB::raw('revisi + 1')]);
            foreach (BentukEpisodeIgd::TABEL_AKTIVITAS as $tabel) {
                DB::table($tabel)->where('episode_id', $lain->id)->update(['episode_id' => $dasar->id, 'diubah_pada' => now()]);
            }
            $lain->forceFill([
                'status' => 'batal', 'alasan_batal' => self::ALASAN_BATAL_EPISODE, 'dibatalkan_oleh' => $olehId, 'dibatalkan_pada' => now(),
                'diubah_oleh' => $olehId, 'revisi' => $lain->revisi + 1,
            ])->save();
        }

        $this->audit->catat('episode.rawat_inap_dibentuk', $olehId, $dasar->getTable(), $dasar->id, [
            'jenis' => Episode::RAWAT_INAP, 'mulai_tanggal' => $dasar->mulai_tanggal->toDateString(), 'episode_digabung' => array_keys($digabung),
            'kunjungan_dipindah' => array_sum($digabung), 'kunjungan_asal_id' => $asal->id,
        ], ['jenis' => $jenisSebelum]);

        return $dasar;
    }
}
