<?php

namespace App\Modules\RawatInap\Application;

use App\Core\Http\SuntinganBerversi;
use App\Modules\RawatInap\Domain\StatusTempatTidur;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Isi peta tempat tidur (tiket 2.27, kontrak v0.35.0; FR-08-01, FR-08-20). Satu kueri: lantai dan gedung ditelusuri ke atas
 * dari ruang kamar lewat inti.lokasi.induk_id (CTE rekursif, leluhur terdekat per jenis), tanpa N+1.
 * - Baris = TT yang sudah mulai berlaku (`berlaku_dari` ≤ hari ini zona RS). TT ditutup (`berlaku_sampai` < hari ini) tetap
 *   tampil sebagai `tidak_dipakai`, `ditutup` true, tidak dapat dipilih; status tersimpannya tidak diubah.
 * - Urut gedung, lantai, bangsal, kode TT; kode urut natural sederhana (panjang dulu, lalu kode: "2" sebelum "10"). Tanpa data pasien kecuali bagian berizin pindah ruang di bawah; `hari_rawat` dari penempatan aktif (2.29).
 * - Bukan daftar berkursor (layar peta memuat seluruh RS sekaligus): paling banyak BATAS_BARIS, lebih → `terpotong`.
 * - `kunjungan_rawat_inap_id` (v0.38.0, keputusan pemilik 10 Nov 2026) hanya untuk TT berpenempatan aktif di unit bangsal
 *   yang ada di `$unitPasien` (gabungan cakupan rawat_inap.pindah_ruang.input dan, sejak 2.40, rawat_inap.pemulangan.lihat
 *   pemanggil); di luar itu kunci tidak dikirim. Diambil di kueri yang sama dengan hari rawat (tanpa N+1).
 *   `pasien_rawat_inap` (nama, No. RM, tanggal lahir; keputusan pemilik 11 Nov 2026) ikut di cabang yang sama, tidak pernah
 *   ditulis ke log/audit.
 * - Status `rencana_pulang` / `melewati_rencana_lama_rawat` (keputusan pemilik 13 Nov 2026, 2.40) dihitung saat dibaca untuk
 *   TT yang ditempati: rencana pulang aktif bertanggal hari ini (zona RS) → rencana_pulang; selain itu hari rawat melewati
 *   rencana lama rawat → melewati_rencana_lama_rawat; selain itu terisi. Tidak disimpan ke status TT maupun riwayatnya.
 */
final class BacaPetaTempatTidur
{
    public const BATAS_BARIS = 1000;

    /** Batas kedalaman penelusuran lokasi ke atas; pohon lokasi paling dalam 6 tingkat (FR-54-11). */
    private const KEDALAMAN_MAKS = 12;

    /**
     * @param  array{bangsal_id?: string|null, gedung_id?: string|null, tempat_tidur_id?: string|null}  $filter
     * @param  list<string>|null  $unitBangsal  unit organisasi bangsal yang terjangkau; null = seluruh RS
     * @param  list<string>|null  $unitPasien  unit bangsal tempat id kunjungan RI dan identitas pasien boleh dikirim; null = seluruh RS, [] = tidak ada
     * @return array{baris: list<array<string, mixed>>, terpotong: bool}
     */
    public function baca(array $filter, ?array $unitBangsal, ?array $unitPasien = []): array
    {
        $ikatan = [self::KEDALAMAN_MAKS];
        $saring = 'true';
        // Satu TT (respons POST) dibaca walau belum mulai berlaku, agar status yang baru diubah selalu bisa dikembalikan.
        if (($filter['tempat_tidur_id'] ?? null) === null) {
            $saring = 'tt.berlaku_dari <= ?::date';
            $ikatan[] = SuntinganBerversi::hariIni();
        }
        foreach (['bangsal_id' => 'b.id', 'gedung_id' => 'gd.id', 'tempat_tidur_id' => 'tt.id'] as $kunci => $kolom) {
            if (($filter[$kunci] ?? null) !== null) {
                $saring .= " AND $kolom = ?";
                $ikatan[] = $filter[$kunci];
            }
        }
        if ($unitBangsal !== null) {
            $saring .= ' AND b.unit_organisasi_id = ANY(?::uuid[])';
            $ikatan[] = '{'.implode(',', $unitBangsal).'}';
        }
        $ikatan[] = self::BATAS_BARIS + 1;
        // Identitas pasien (keputusan pemilik 11 Nov dan 13 Nov 2026) hanya dibaca bila pemanggil boleh pindah ruang atau
        // melihat pemulangan di suatu bangsal.
        $bacaPasien = $unitPasien !== [];
        $kolomPasien = $bacaPasien ? 'ps.nama AS pasien_nama, ps.no_rm AS pasien_no_rm, ps.tanggal_lahir AS pasien_tanggal_lahir'
            : 'NULL AS pasien_nama, NULL AS pasien_no_rm, NULL AS pasien_tanggal_lahir';
        $gabungPasien = $bacaPasien ? 'LEFT JOIN pasien.pasien ps ON ps.id = pn.pasien_id' : '';

        $hasil = DB::select('WITH RECURSIVE naik AS (
                SELECT km.lokasi_id AS ruang_id, l.induk_id AS lokasi_id, 1 AS jarak
                FROM inti.kamar km JOIN inti.lokasi l ON l.id = km.lokasi_id
                WHERE l.induk_id IS NOT NULL
                UNION ALL
                SELECT naik.ruang_id, l.induk_id, naik.jarak + 1
                FROM naik JOIN inti.lokasi l ON l.id = naik.lokasi_id
                WHERE l.induk_id IS NOT NULL AND naik.jarak < ?
            ), leluhur AS (
                SELECT DISTINCT ON (naik.ruang_id, l.jenis) naik.ruang_id, l.jenis, l.id, l.kode, l.nama
                FROM naik JOIN inti.lokasi l ON l.id = naik.lokasi_id
                WHERE l.jenis IN (\'lantai\', \'gedung\')
                ORDER BY naik.ruang_id, l.jenis, naik.jarak
            )
            SELECT tt.id, tt.kode, tt.aturan_jenis_kelamin, tt.isolasi, tt.ekstra, tt.status_saat_ini, tt.status_sejak, tt.berlaku_sampai,
                km.id AS kamar_id, rg.kode AS kamar_kode, rg.nama AS kamar_nama,
                b.id AS bangsal_id, b.kode AS bangsal_kode, b.nama AS bangsal_nama,
                lt.id AS lantai_id, lt.kode AS lantai_kode, lt.nama AS lantai_nama,
                gd.id AS gedung_id, gd.kode AS gedung_kode, gd.nama AS gedung_nama,
                kr.id AS kelas_id, kr.kode AS kelas_kode, kr.nama AS kelas_nama, pn.mulai_pertama,
                pn.kunjungan_id, b.unit_organisasi_id AS bangsal_unit_id, rp.rencana_pulang_pada, rp.rencana_lama_rawat, '.$kolomPasien.'
            FROM inti.tempat_tidur tt
            JOIN inti.kamar km ON km.id = tt.kamar_id
            JOIN inti.lokasi rg ON rg.id = km.lokasi_id
            JOIN inti.bangsal b ON b.id = km.bangsal_id
            JOIN inti.kelas_rawat kr ON kr.id = tt.kelas_rawat_id
            LEFT JOIN leluhur lt ON lt.ruang_id = km.lokasi_id AND lt.jenis = \'lantai\'
            LEFT JOIN leluhur gd ON gd.ruang_id = km.lokasi_id AND gd.jenis = \'gedung\'
            LEFT JOIN LATERAL (SELECT min(p2.mulai_pada) AS mulai_pertama, (array_agg(p1.kunjungan_id))[1] AS kunjungan_id,
                    (array_agg(p1.pasien_id))[1] AS pasien_id
                FROM kunjungan.penempatan_tempat_tidur p1 JOIN kunjungan.penempatan_tempat_tidur p2 ON p2.kunjungan_id = p1.kunjungan_id
                WHERE p1.tempat_tidur_id = tt.id AND p1.selesai_pada IS NULL) pn ON true
            LEFT JOIN kunjungan.rencana_pulang rp ON rp.kunjungan_id = pn.kunjungan_id AND rp.status = \'direncanakan\'
            '.$gabungPasien.'
            WHERE '.$saring.'
            ORDER BY length(gd.kode) NULLS LAST, gd.kode, gd.id, length(lt.kode) NULLS LAST, lt.kode, lt.id,
                length(b.kode), b.kode, length(tt.kode), tt.kode
            LIMIT ?', $ikatan);

        return [
            'baris' => array_values(array_map(fn (stdClass $b) => $this->bentuk($b, $unitPasien), array_slice($hasil, 0, self::BATAS_BARIS))),
            'terpotong' => count($hasil) > self::BATAS_BARIS,
        ];
    }

    /**
     * Satu TT dalam bentuk baris peta (respons POST /tempat-tidur/{id}/status); null bila tidak ada.
     *
     * @return array<string, mixed>|null
     */
    public function satu(string $tempatTidurId): ?array
    {
        return $this->baca(['tempat_tidur_id' => $tempatTidurId], null)['baris'][0] ?? null;
    }

    /**
     * @param  list<string>|null  $unitPasien
     * @return array<string, mixed>
     */
    private function bentuk(stdClass $b, ?array $unitPasien): array
    {
        $ditutup = UbahStatusTempatTidur::ditutup($b->berlaku_sampai);
        $status = $ditutup ? StatusTempatTidur::TIDAK_DIPAKAI : (string) $b->status_saat_ini;
        $hariRawat = $ditutup || $b->mulai_pertama === null ? null : BacaRawatInap::hariRawat((string) $b->mulai_pertama);
        if ($hariRawat !== null && in_array($status, StatusTempatTidur::DITEMPATI, true)) {
            $status = self::statusDitempati($hariRawat, $b->rencana_pulang_pada === null ? null : substr((string) $b->rencana_pulang_pada, 0, 10),
                $b->rencana_lama_rawat === null ? null : (int) $b->rencana_lama_rawat);
        }
        $ringkas = fn (mixed $id, mixed $kode, mixed $nama) => $id === null ? null : ['id' => (string) $id, 'kode' => (string) $kode, 'nama' => (string) $nama];

        $baris = [
            'id' => (string) $b->id,
            'kode' => (string) $b->kode,
            'kamar' => $ringkas($b->kamar_id, $b->kamar_kode, $b->kamar_nama),
            'bangsal' => $ringkas($b->bangsal_id, $b->bangsal_kode, $b->bangsal_nama),
            'lantai' => $ringkas($b->lantai_id, $b->lantai_kode, $b->lantai_nama),
            'gedung' => $ringkas($b->gedung_id, $b->gedung_kode, $b->gedung_nama),
            'kelas_rawat' => $ringkas($b->kelas_id, $b->kelas_kode, $b->kelas_nama),
            'aturan_jenis_kelamin' => (string) $b->aturan_jenis_kelamin,
            'isolasi' => (bool) $b->isolasi,
            'ekstra' => (bool) $b->ekstra,
            'status' => $status,
            'status_sejak' => Carbon::parse((string) $b->status_sejak)->toIso8601String(),
            'ditutup' => $ditutup,
            // Penutupan terjadwal (berlaku_sampai terisi) juga tidak dapat dipilih: alokasi 2.28 menolaknya.
            'dapat_dipilih' => $b->berlaku_sampai === null && $status === StatusTempatTidur::KOSONG_SIAP,
            // Penempatan aktif di TT ini; hitungan sejak penempatan pertama kunjungannya (pindah ruang tidak mengulang).
            'hari_rawat' => $hariRawat,
        ];
        // Data bagian yang tidak diizinkan tidak dikirim (PRD 9.2): kunci hanya ada bila pemanggil boleh memindahkan atau
        // memulangkan pasien di bangsal ini.
        if (! $ditutup && $b->kunjungan_id !== null
            && ($unitPasien === null || in_array((string) $b->bangsal_unit_id, $unitPasien, true))) {
            $baris['kunjungan_rawat_inap_id'] = (string) $b->kunjungan_id;
            // Dua penanda identitas untuk konfirmasi pindah ruang/pulang (PRD 5.1 butir 9); syarat izin sama dengan id kunjungan.
            $baris['pasien_rawat_inap'] = [
                'nama' => (string) $b->pasien_nama,
                'no_rm' => (string) $b->pasien_no_rm,
                'tanggal_lahir' => $b->pasien_tanggal_lahir === null ? null : substr((string) $b->pasien_tanggal_lahir, 0, 10),
            ];
        }

        return $baris;
    }

    /**
     * Status TT yang ditempati (keputusan pemilik 13 Nov 2026): rencana pulang hari ini diutamakan, lalu hari rawat melewati
     * rencana lama rawat, selain itu terisi.
     *
     * @param  string|null  $rencanaPulangPada  tanggal rencana pulang aktif (Y-m-d) atau null
     */
    public static function statusDitempati(int $hariRawat, ?string $rencanaPulangPada, ?int $rencanaLamaRawat): string
    {
        return match (true) {
            $rencanaPulangPada === SuntinganBerversi::hariIni() => StatusTempatTidur::RENCANA_PULANG,
            $rencanaLamaRawat !== null && $hariRawat > $rencanaLamaRawat => StatusTempatTidur::MELEWATI_RENCANA_LAMA_RAWAT,
            default => StatusTempatTidur::TERISI,
        };
    }
}
