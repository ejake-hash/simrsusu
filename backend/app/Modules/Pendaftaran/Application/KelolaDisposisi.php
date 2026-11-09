<?php

namespace App\Modules\Pendaftaran\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Instalasi\StatusInstalasi;
use App\Modules\Akses\Application\KelolaRelasiPerawatan;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\MasterData\Models\AturanPerpindahan;
use App\Modules\Pendaftaran\Events\DisposisiDicatat;
use App\Modules\Pendaftaran\Events\DisposisiRawatInap;
use App\Modules\Pendaftaran\Models\Episode;
use App\Modules\Pendaftaran\Models\Kunjungan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Disposisi IGD sebagai perintah perpindahan (tiket 2.26, kontrak v0.33.0; FR-03-09, FR-00-02, FR-00-03, PRD 7.00.3).
 * Tujuan harus ada di matriks perpindahan aktif (data induk) dengan pos asal `igd`, dan pemberi perintah harus memegang
 * izin sel matriks itu (AccessEvaluator, termasuk SIP/SPK berlaku bila izinnya klinis). Setiap perintah = baris baru
 * kunjungan.perpindahan_pos (hanya ditambah).
 * - pulang, aps, meninggal, doa, rujuk_keluar: kunjungan IGD selesai; episode IGD > 6 jam ikut selesai (7.00.2), dan bila
 *   sudah lewat 6 jam tetapi tugas terjadwal belum memindahkannya, episode itu dibentuk dulu. Episode rawat jalan harian
 *   tetap mengikuti aturan yang ada (tidak ditutup di sini).
 * - rawat_inap: permintaan kamar saja (status `menunggu_kamar`, kejadian DisposisiRawatInap); kunjungan tetap di IGD,
 *   penempatan dan episode rawat inap di 2.28/2.29.
 * - kamar_operasi: status `menunggu_kamar_operasi`, kunjungan tetap di IGD sampai modul kamar operasi menerimanya.
 * Alasan dan serah terima klinis: tidak ke log/audit.
 */
final class KelolaDisposisi
{
    public const POS_ASAL = 'igd';

    /** Kode disposisi FR-03-09 (= kode pos tujuan matriks). */
    public const TUJUAN = ['pulang', 'rawat_inap', 'kamar_operasi', 'rujuk_keluar', 'aps', 'meninggal', 'doa'];

    /** Tujuan yang memindahkan pasien antar-ruang di dalam RS: serah terima (RM-15) wajib (7.00.3). */
    public const BUTUH_SERAH_TERIMA = ['rawat_inap', 'kamar_operasi'];

    /** Tujuan yang menahan pasien di IGD sampai pos tujuan menerimanya; selain ini kunjungan IGD selesai. */
    private const MENUNGGU = ['rawat_inap' => 'menunggu_kamar', 'kamar_operasi' => 'menunggu_kamar_operasi'];

    public function __construct(
        private readonly CatatAktivitas $audit,
        private readonly StatusInstalasi $instalasi,
        private readonly KelolaRelasiPerawatan $relasi,
        private readonly BentukEpisodeIgd $episodeIgd,
    ) {}

    /**
     * @param  array{tujuan: string, alasan: string, serah_terima?: string|null}  $isi  sudah divalidasi bentuknya
     * @return string id perintah (kunjungan.perpindahan_pos)
     */
    public function catat(Pengguna $dokter, string $kunjunganId, array $isi): string
    {
        KelolaPasien::pastikanSiapMelayani($this->instalasi);
        $tujuan = $isi['tujuan'];

        return DB::transaction(function () use ($dokter, $kunjunganId, $isi, $tujuan) {
            $k = KunciKunjungan::pasienLaluKunjungan($kunjunganId) ?? throw new ApiError(404, 'tidak_ditemukan', 'Kunjungan tidak ditemukan.');
            if ($k->jenis_kunjungan !== Kunjungan::IGD || ! in_array($k->status, Kunjungan::STATUS_AKTIF, true)) {
                throw new ApiError(409, 'status_tidak_sesuai', 'Disposisi hanya untuk kunjungan IGD yang masih terdaftar atau dilayani.',
                    [['jenis_kunjungan' => $k->jenis_kunjungan, 'status' => $k->status]]);
            }
            $aturan = AturanPerpindahan::berlaku(self::POS_ASAL, $tujuan)
                ?? throw new ApiError(422, 'perpindahan_di_luar_matriks', 'Tujuan ini tidak ada di matriks perpindahan aktif untuk IGD.',
                    [['kolom' => 'tujuan', 'pos_asal' => self::POS_ASAL, 'pos_tujuan' => $tujuan, 'pesan' => 'Pilih tujuan yang diizinkan matriks perpindahan.']]);
            // Peran pemberi perintah menurut matriks, lewat AccessEvaluator (lapis 1–3 dan 5), bukan cek peran langsung.
            Gate::forUser($dokter)->authorize($aturan->izin_perintah, Cakupan::rs());

            $status = self::MENUNGGU[$tujuan] ?? 'selesai';
            if ($status !== 'selesai' && DB::table('kunjungan.perpindahan_pos')->where('dari_kunjungan_id', $k->id)->where('status', $status)->exists()) {
                throw new ApiError(409, 'perintah_sudah_menunggu', 'Perintah ini sudah tercatat dan masih menunggu. Buka riwayat disposisi.',
                    [['pos_tujuan' => $tujuan, 'status' => $status]]);
            }

            $statusKunjungan = $k->status;
            $episodeBaru = null;
            if ($status === 'selesai') {
                // Lewat 6 jam tetapi tugas terjadwal belum berjalan: bentuk episode IGD > 6 jam dulu (7.00.2 baris 3).
                $episodeBaru = $this->episodeIgd->pindahkanTerkunci($k);
                $k->forceFill(['status' => 'selesai', 'diubah_oleh' => $dokter->id, 'revisi' => $k->revisi + 1])->save();
                $this->relasi->akhiri('kunjungan', $k->id, 'Disposisi IGD', $dokter->id);
            }
            $episode = Episode::query()->whereKey($k->episode_id)->lockForUpdate()->firstOrFail();
            if ($status === 'selesai' && $episode->jenis === Episode::IGD_LEBIH_6_JAM && $episode->status === 'aktif') {
                $episode->forceFill(['status' => 'selesai', 'selesai_pada' => now(), 'diubah_oleh' => $dokter->id, 'revisi' => $episode->revisi + 1])->save();
            }

            $id = (string) Str::uuid7();
            $jam = now()->utc();
            DB::table('kunjungan.perpindahan_pos')->insert([
                'id' => $id, 'episode_id' => $episode->id, 'dari_kunjungan_id' => $k->id, 'aturan_perpindahan_id' => $aturan->id,
                'pos_asal' => $aturan->pos_asal, 'pos_tujuan' => $aturan->pos_tujuan, 'izin_perintah' => $aturan->izin_perintah,
                'alasan' => trim($isi['alasan']), 'serah_terima' => self::teks($isi['serah_terima'] ?? null), 'status' => $status,
                'episode_jenis' => $episode->jenis, 'episode_status' => $episode->status, 'diputuskan_oleh' => $dokter->id,
                // Format eksplisit: binding Carbon bawaan membuang mikrodetik, padahal urutan riwayat memakai jam ini.
                'terjadi_pada' => $jam->format('Y-m-d H:i:s.uP'), 'dibuat_pada' => $jam->format('Y-m-d H:i:s.uP'),
            ]);

            DisposisiDicatat::dispatch($id, $k->id, $k->pasien_id, $episode->id, $tujuan, $status, $dokter->id, $jam->toIso8601String());
            if ($tujuan === 'rawat_inap') {
                DisposisiRawatInap::dispatch($id, $k->id, $k->pasien_id, $episode->id, $dokter->id);
            }
            $this->audit->catat('kunjungan.disposisi', $dokter->id, 'kunjungan.perpindahan_pos', $id, [
                'kunjungan_id' => $k->id, 'nomor' => $k->nomor, 'pos_asal' => self::POS_ASAL, 'pos_tujuan' => $tujuan, 'status' => $status,
                'aturan_perpindahan_id' => $aturan->id, 'episode_id' => $episode->id, 'episode_jenis' => $episode->jenis,
                'episode_status' => $episode->status, 'episode_igd_dibentuk' => $episodeBaru !== null, 'status_kunjungan' => $k->status,
            ], ['status_kunjungan' => $statusKunjungan]);

            return $id;
        });
    }

    private static function teks(?string $nilai): ?string
    {
        return $nilai === null || trim($nilai) === '' ? null : trim($nilai);
    }
}
