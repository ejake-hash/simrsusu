<?php

namespace App\Modules\Pendaftaran\Application;

use App\Core\Audit\CatatAktivitas;
use App\Modules\Pendaftaran\Events\EpisodeIgdDibentuk;
use App\Modules\Pendaftaran\Models\Episode;
use App\Modules\Pendaftaran\Models\Kunjungan;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Episode "IGD > 6 jam tanpa rawat inap" (7.00.2 baris 3; tiket 2.23, keputusan pemilik 9 Nov 2026): kunjungan IGD yang
 * masih terdaftar/dilayani lebih dari 6 jam sejak tiba keluar dari episode rawat jalan harian ke episode baru, dan
 * aktivitas kunjungan itu (baris yang menyimpan episode_id) ikut pindah. Menyimpang dari PRD 7.00.5 butir 1 dengan
 * sengaja. Pelaku sistem; aman diulang.
 */
final class BentukEpisodeIgd
{
    public const ALASAN_BATAL_EPISODE = 'Kunjungan dipindah ke episode IGD > 6 jam';

    /**
     * Tabel aktivitas yang menyimpan episode_id dan kunjungan_id. Tabel baru dengan episode_id wajib ditambahkan di sini.
     * farmasi.resep dan klinis.diagnosis hanya menyimpan kunjungan_id, jadi ikut lewat kunjungannya.
     */
    public const TABEL_AKTIVITAS = ['klinis.catatan_klinis', 'permintaan.permintaan_pemeriksaan', 'integrasi.sep'];

    /** Status perintah disposisi yang menahan pasien di IGD sambil menunggu dipindah (kunjungan.perpindahan_pos). */
    public const STATUS_MENUNGGU_PINDAH = ['menunggu_kamar', 'menunggu_kamar_operasi'];

    /**
     * Saring perintah (alias $alias di kunjungan.perpindahan_pos) yang masih menahan pasien: berstatus menunggu, dan
     * permintaan kamar yang lahir darinya tidak batal atau sudah ditempatkan. Baris perintah hanya ditambah, jadi
     * pembatalan permintaan kamar (tiket 2.29, POST /permintaan-tempat-tidur/{id}/batal) melepas perintahnya lewat sini.
     *
     * @param  list<string>  $status
     */
    public static function saringMasihMenunggu(Builder $q, string $alias, array $status = self::STATUS_MENUNGGU_PINDAH): Builder
    {
        return $q->whereIn("$alias.status", $status)->whereNotExists(fn (Builder $r) => $r->from('kunjungan.permintaan_tempat_tidur AS ptt_tutup')
            ->whereColumn('ptt_tutup.perpindahan_pos_id', "$alias.id")->whereIn('ptt_tutup.status', ['batal', 'ditempatkan']));
    }

    public function __construct(private readonly CatatAktivitas $audit) {}

    /** @return int jumlah kunjungan yang dipindah */
    public function jalankan(int $batas = 200): int
    {
        $jumlah = 0;
        do {
            $ids = DB::table('kunjungan.kunjungan AS k')
                ->join('kunjungan.episode AS e', 'e.id', '=', 'k.episode_id')
                ->where('k.jenis_kunjungan', Kunjungan::IGD)->whereIn('k.status', Kunjungan::STATUS_AKTIF)
                ->where('k.tiba_pada', '<', self::ambang())->where('e.jenis', Episode::RAWAT_JALAN_HARIAN)
                // Sudah diputuskan rawat inap/operasi (disposisi 2.26): bukan "IGD > 6 jam tanpa rawat inap"; episodenya
                // mengikuti SPRI (2.28). Disposisi akhir sesudahnya (mis. APS) membentuk episode ini lewat pindahkanTerkunci().
                ->whereNotExists(fn (Builder $q) => self::saringMasihMenunggu($q->from('kunjungan.perpindahan_pos AS p')->whereColumn('p.dari_kunjungan_id', 'k.id'), 'p'))
                ->orderBy('k.tiba_pada')->orderBy('k.id')->limit($batas)->pluck('k.id');
            $dipindah = 0;
            foreach ($ids as $id) {
                $dipindah += DB::transaction(fn () => $this->pindahkan((string) $id)) ? 1 : 0;
            }
            $jumlah += $dipindah;
        } while ($ids->count() === $batas && $dipindah > 0);

        return $jumlah;
    }

    /**
     * Tepat 6 jam belum melewati batas (sama dengan Kunjungan::lebihEnamJam). UTC: binding waktu dikirim tanpa offset, jadi
     * zona instance harus sama dengan zona sesi basis data.
     */
    private static function ambang(): Carbon
    {
        return now()->utc()->subHours(Kunjungan::BATAS_JAM_IGD);
    }

    /** Kunci pasien lalu kunjungan (KunciKunjungan, urutan daftar/batal), lalu periksa ulang syaratnya di bawah kunci. */
    private function pindahkan(string $kunjunganId): bool
    {
        // Gabung RM yang commit di antara baca dan kunci: lewati (null); putaran berikutnya mengulang dengan pasien yang benar.
        $kunjungan = KunciKunjungan::pasienLaluKunjungan($kunjunganId, lewatiBilaPindah: true);
        if ($kunjungan === null || self::saringMasihMenunggu(DB::table('kunjungan.perpindahan_pos AS p')->where('p.dari_kunjungan_id', $kunjungan->id), 'p')->exists()) {
            return false;
        }

        return $this->pindahkanTerkunci($kunjungan) !== null;
    }

    /**
     * Pindahkan satu kunjungan IGD aktif yang sudah lewat 6 jam ke episode `igd_lebih_6_jam` (pelaku sistem: aturan
     * otomatis). Pemanggil sudah memegang kunci pasien lalu kunjungan (KunciKunjungan) di transaksinya; dipakai tugas
     * terjadwal dan disposisi (2.26), supaya disposisi sesudah 6 jam yang mendahului tugas terjadwal tetap masuk episode
     * yang benar.
     *
     * @return Episode|null episode baru; null bila syaratnya tidak terpenuhi (tidak ada yang diubah)
     */
    public function pindahkanTerkunci(Kunjungan $kunjungan): ?Episode
    {
        if ($kunjungan->jenis_kunjungan !== Kunjungan::IGD || $kunjungan->tiba_pada === null
            || ! in_array($kunjungan->status, Kunjungan::STATUS_AKTIF, true) || ! $kunjungan->tiba_pada->lessThan(self::ambang())) {
            return null;
        }
        $lama = Episode::query()->whereKey($kunjungan->episode_id)->lockForUpdate()->first();
        if ($lama === null || $lama->jenis !== Episode::RAWAT_JALAN_HARIAN) {
            return null;
        }

        $baru = new Episode;
        $baru->forceFill([
            'pasien_id' => $kunjungan->pasien_id, 'jenis' => Episode::IGD_LEBIH_6_JAM,
            'mulai_tanggal' => $kunjungan->tiba_pada->copy()->setTimezone((string) config('instalasi.zona_waktu'))->toDateString(),
        ])->save();

        $dipindah = [];
        foreach (self::TABEL_AKTIVITAS as $tabel) {
            $dipindah[$tabel] = DB::table($tabel)->where('kunjungan_id', $kunjungan->id)->where('episode_id', $lama->id)
                ->update(['episode_id' => $baru->id, 'diubah_pada' => now()]);
        }
        $kunjungan->forceFill(['episode_id' => $baru->id, 'diubah_oleh' => null, 'revisi' => $kunjungan->revisi + 1])->save();

        $lamaBatal = ! Kunjungan::query()->where('episode_id', $lama->id)->where('status', '<>', 'batal')->exists();
        if ($lamaBatal) {
            $lama->forceFill([
                'status' => 'batal', 'alasan_batal' => self::ALASAN_BATAL_EPISODE, 'dibatalkan_oleh' => null, 'dibatalkan_pada' => now(),
                'diubah_oleh' => null, 'revisi' => $lama->revisi + 1,
            ])->save();
        }

        EpisodeIgdDibentuk::dispatch($baru->id, $lama->id, $kunjungan->id, $kunjungan->pasien_id);
        $this->audit->catat('episode.dipindah_igd_6_jam', null, $baru->getTable(), $baru->id, [
            'kunjungan_id' => $kunjungan->id, 'nomor' => $kunjungan->nomor, 'episode_lama_id' => $lama->id,
            'episode_lama_batal' => $lamaBatal, 'baris_dipindah' => $dipindah,
        ], ['episode_id' => $lama->id]);

        return $baru;
    }
}
