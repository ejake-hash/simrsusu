<?php

namespace App\Modules\Pendaftaran\Application;

use App\Modules\MasterData\Models\AturanPapanIgd;
use App\Modules\Pendaftaran\Models\Kunjungan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Isi papan pasien IGD (tiket 2.25, kontrak v0.34.0; FR-03-03, FR-03-04, FR-03-05 bagian papan, FR-00-06).
 * - Baris = kunjungan IGD yang masih terdaftar/dilayani (pasien selesai disposisi atau batal tidak tampil).
 * - Urut prioritas triase berlaku lalu jam tiba (FR-03-03). Keputusan pemilik 9 Nov 2026: belum ditriase paling atas
 *   (belum dinilai, jadi tidak boleh tenggelam di bawah pasien hijau), lalu merah → kuning → hijau → hitam (FR-03-02).
 * - Waktu dihitung server dengan jam aplikasi (`now()`). Satu aturan pembulatan: tanda dibandingkan per detik, menit
 *   ditampilkan dibulatkan ke atas, jadi `menit > batas` selalu sama dengan tanda lewat/terlambat.
 * - Waktu tanggap (FR-03-04; keputusan pemilik 9 Nov 2026) = jam tiba → dokter MULAI mengisi asesmen awal IGD: catatan
 *   klinis `asesmen` tatanan `igd`, profesi dokter atau PPDS, termasuk draf, `min(dibuat_pada)`. catatan_klinis tidak
 *   punya status batal dan tidak dihapus, jadi tidak ada yang dikecualikan. Tanpa tahap peringatan: `menunggu` sampai
 *   batas, lalu `lewat_batas`. Asumsi: layar asesmen awal medis IGD (FR-03-08) belum dibangun, jadi sampai itu ada semua
 *   pasien tampil `menunggu_asesmen`.
 * - Batas waktu dari data induk inti.aturan_papan_igd; batas 6 jam = Kunjungan::BATAS_JAM_IGD (INA-CBG D.1.f/D.2.b).
 *   Ambang mendekati 6 jam kosong = tanda `mendekati_6_jam` tidak dipakai (keputusan pemilik 9 Nov 2026).
 * Keluhan klinis dikirim hanya ke pemegang izin papan (dicek rute) dan tidak pernah ke log/audit.
 */
final class BacaPapanIgd
{
    /** Batas baris satu respons; pasien aktif IGD biasanya puluhan. Lebih dari ini → meta.terpotong. */
    public const BATAS_BARIS = 200;

    /** Urutan prioritas FR-03-03; null (belum ditriase) = 0. */
    private const URUT_TRIASE = "CASE t.kategori WHEN 'merah' THEN 1 WHEN 'kuning' THEN 2 WHEN 'hijau' THEN 3 WHEN 'hitam' THEN 4 ELSE 0 END";

    /**
     * @return array{baris: array<int, array<string, mixed>>, terpotong: bool, aturan: array<string, int|null>, area: array<int, array<string, mixed>>}
     */
    public function baca(?string $areaId): array
    {
        $aturan = AturanPapanIgd::berlaku();
        $ikatan = [Kunjungan::IGD, ...Kunjungan::STATUS_AKTIF];
        $saringArea = '';
        if ($areaId !== null) {
            $saringArea = 'AND pi.area_igd_id = ?';
            $ikatan[] = $areaId;
        }
        $ikatan[] = self::BATAS_BARIS + 1;

        $hasil = DB::select('SELECT k.id, k.nomor, k.status, k.tiba_pada, k.keluhan AS keluhan_daftar,
                p.id AS pasien_id, p.no_rm, p.nama, p.jenis_kelamin, p.tanggal_lahir,
                t.kategori, t.ditriase_pada, t.keluhan AS keluhan_triase,
                a.id AS area_id, a.kode AS area_kode, a.nama AS area_nama,
                (SELECT min(c.dibuat_pada) FROM klinis.catatan_klinis c WHERE c.kunjungan_id = k.id AND c.jenis = \'asesmen\'
                    AND c.tatanan = \'igd\' AND c.profesi IN (\'dokter\', \'ppds\')) AS asesmen_dokter_pada,
                d.status AS menunggu_status, d.terjadi_pada AS menunggu_sejak,
                pk.id AS permintaan_id, pk.status AS permintaan_status, pk.tt_id, pk.tt_kode, pk.bangsal_id, pk.bangsal_kode, pk.bangsal_nama
            FROM kunjungan.kunjungan k
            JOIN pasien.pasien p ON p.id = k.pasien_id
            LEFT JOIN kunjungan.papan_igd pi ON pi.kunjungan_id = k.id
            LEFT JOIN inti.area_igd a ON a.id = pi.area_igd_id
            LEFT JOIN LATERAL (SELECT tr.kategori, tr.ditriase_pada, tr.keluhan FROM kunjungan.triase tr WHERE tr.kunjungan_id = k.id
                ORDER BY tr.ditriase_pada DESC, tr.id DESC LIMIT 1) t ON true
            LEFT JOIN LATERAL (SELECT pp.status, pp.terjadi_pada FROM kunjungan.perpindahan_pos pp WHERE pp.dari_kunjungan_id = k.id
                AND pp.status IN (\'menunggu_kamar\', \'menunggu_kamar_operasi\')
                AND NOT EXISTS (SELECT 1 FROM kunjungan.permintaan_tempat_tidur pt WHERE pt.perpindahan_pos_id = pp.id AND pt.status IN (\'batal\', \'ditempatkan\'))
                ORDER BY pp.terjadi_pada DESC, pp.id DESC LIMIT 1) d ON true
            LEFT JOIN LATERAL (SELECT ptt.id, ptt.status, tt.id AS tt_id, tt.kode AS tt_kode, b.id AS bangsal_id, b.kode AS bangsal_kode, b.nama AS bangsal_nama
                FROM kunjungan.permintaan_tempat_tidur ptt
                LEFT JOIN inti.tempat_tidur tt ON tt.id = ptt.tempat_tidur_id
                LEFT JOIN inti.kamar km ON km.id = tt.kamar_id
                LEFT JOIN inti.bangsal b ON b.id = km.bangsal_id
                WHERE ptt.kunjungan_id = k.id AND ptt.status IN (\'menunggu\', \'dialokasikan\') LIMIT 1) pk ON true
            WHERE k.jenis_kunjungan = ? AND k.status IN ('.implode(', ', array_fill(0, count(Kunjungan::STATUS_AKTIF), '?')).') '.$saringArea.'
            ORDER BY '.self::URUT_TRIASE.', k.tiba_pada, k.id
            LIMIT ?', $ikatan);

        $terpotong = count($hasil) > self::BATAS_BARIS;
        $sekarang = now();
        $baris = array_map(fn (stdClass $b) => $this->bentuk($b, $aturan, $sekarang), array_slice($hasil, 0, self::BATAS_BARIS));

        return [
            'baris' => $baris,
            'terpotong' => $terpotong,
            'aturan' => [
                'batas_tanggap_menit' => $aturan->batas_tanggap_menit, 'batas_lama_igd_menit' => Kunjungan::BATAS_JAM_IGD * 60, 'peringatan_lama_igd_menit' => $aturan->peringatan_lama_igd_menit,
            ],
            'area' => DB::table('inti.area_igd')->where('status', 'aktif')->orderBy('urutan')->orderBy('kode')->limit(self::BATAS_BARIS)
                ->get(['id', 'kode', 'nama'])->map(fn (stdClass $a) => ['id' => $a->id, 'kode' => $a->kode, 'nama' => $a->nama])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function bentuk(stdClass $b, AturanPapanIgd $aturan, Carbon $sekarang): array
    {
        $tiba = Carbon::parse($b->tiba_pada);
        $detikDiIgd = self::detik($tiba, $sekarang);
        $batasLama = Kunjungan::BATAS_JAM_IGD * 3600;
        $tandaLama = match (true) {
            // Sama dengan Kunjungan::lebihEnamJam(): lewat sedetik pun sudah lebih dari 6 jam.
            $detikDiIgd > $batasLama => 'lebih_6_jam',
            $aturan->peringatan_lama_igd_menit !== null && $batasLama - $detikDiIgd <= $aturan->peringatan_lama_igd_menit * 60 => 'mendekati_6_jam',
            default => 'aman',
        };

        $batasTanggap = $aturan->batas_tanggap_menit * 60;
        if ($b->asesmen_dokter_pada !== null) {
            $asesmen = Carbon::parse($b->asesmen_dokter_pada);
            $detikTanggap = self::detik($tiba, $asesmen);
            $tanggap = ['asesmen_dokter_pada' => $asesmen->toIso8601String(), 'menit' => self::menit($detikTanggap), 'menunggu_asesmen' => false,
                'tanda' => $detikTanggap <= $batasTanggap ? 'tercapai' : 'terlambat'];
        } else {
            $tanggap = ['asesmen_dokter_pada' => null, 'menit' => null, 'menunggu_asesmen' => true,
                'tanda' => $detikDiIgd <= $batasTanggap ? 'menunggu' : 'lewat_batas'];
        }

        return [
            'kunjungan_id' => $b->id,
            'nomor' => $b->nomor,
            'status' => $b->status,
            'pasien' => ['id' => $b->pasien_id, 'no_rm' => $b->no_rm, 'nama' => $b->nama, 'jenis_kelamin' => $b->jenis_kelamin,
                'tanggal_lahir' => $b->tanggal_lahir === null ? null : substr((string) $b->tanggal_lahir, 0, 10)],
            // Keluhan triase berlaku bila diisi, selain itu keluhan saat registrasi.
            'keluhan' => $b->keluhan_triase ?? $b->keluhan_daftar,
            'triase' => $b->kategori === null ? null : ['kategori' => $b->kategori, 'ditriase_pada' => Carbon::parse($b->ditriase_pada)->toIso8601String()],
            'area' => $b->area_id === null ? null : ['id' => $b->area_id, 'kode' => $b->area_kode, 'nama' => $b->area_nama],
            'tiba_pada' => $tiba->toIso8601String(),
            'lama_di_igd_menit' => self::menit($detikDiIgd),
            'tanda_lama_igd' => $tandaLama,
            'lebih_6_jam' => $tandaLama === 'lebih_6_jam',
            'tanggap' => $tanggap,
            'disposisi_menunggu' => $b->menunggu_status === null ? null
                : ['status' => $b->menunggu_status, 'sejak' => Carbon::parse($b->menunggu_sejak)->toIso8601String()],
            // FR-03-06 (2.28): permintaan kamar aktif dan TT yang dipesan, dari tabel yang sama dengan peta TT.
            'permintaan_kamar' => $b->permintaan_id === null ? null : [
                'id' => $b->permintaan_id, 'status' => $b->permintaan_status,
                'tempat_tidur' => $b->tt_id === null ? null : ['id' => $b->tt_id, 'kode' => $b->tt_kode,
                    'bangsal' => ['id' => $b->bangsal_id, 'kode' => $b->bangsal_kode, 'nama' => $b->bangsal_nama]],
            ],
        ];
    }

    /** Menit tampil = dibulatkan ke atas (5 menit 1 detik → 6), selaras dengan tanda yang dibandingkan per detik. */
    private static function menit(float $detik): int
    {
        return (int) ceil($detik / 60);
    }

    /** Selisih detik (dengan pecahan milidetik), tidak negatif. */
    private static function detik(Carbon $dari, Carbon $sampai): float
    {
        return max(0.0, ($sampai->getTimestampMs() - $dari->getTimestampMs()) / 1000);
    }
}
