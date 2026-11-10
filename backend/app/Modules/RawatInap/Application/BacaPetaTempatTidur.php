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
 * - Urut gedung, lantai, bangsal, kode TT; kode urut natural sederhana (panjang dulu, lalu kode: "2" sebelum "10"). Tanpa data pasien apa pun; `hari_rawat` dari penempatan aktif (2.29).
 * - Bukan daftar berkursor (layar peta memuat seluruh RS sekaligus): paling banyak BATAS_BARIS, lebih → `terpotong`.
 */
final class BacaPetaTempatTidur
{
    public const BATAS_BARIS = 1000;

    /** Batas kedalaman penelusuran lokasi ke atas; pohon lokasi paling dalam 6 tingkat (FR-54-11). */
    private const KEDALAMAN_MAKS = 12;

    /**
     * @param  array{bangsal_id?: string|null, gedung_id?: string|null, tempat_tidur_id?: string|null}  $filter
     * @param  list<string>|null  $unitBangsal  unit organisasi bangsal yang terjangkau; null = seluruh RS
     * @return array{baris: list<array<string, mixed>>, terpotong: bool}
     */
    public function baca(array $filter, ?array $unitBangsal): array
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
                kr.id AS kelas_id, kr.kode AS kelas_kode, kr.nama AS kelas_nama, pn.mulai_pertama
            FROM inti.tempat_tidur tt
            JOIN inti.kamar km ON km.id = tt.kamar_id
            JOIN inti.lokasi rg ON rg.id = km.lokasi_id
            JOIN inti.bangsal b ON b.id = km.bangsal_id
            JOIN inti.kelas_rawat kr ON kr.id = tt.kelas_rawat_id
            LEFT JOIN leluhur lt ON lt.ruang_id = km.lokasi_id AND lt.jenis = \'lantai\'
            LEFT JOIN leluhur gd ON gd.ruang_id = km.lokasi_id AND gd.jenis = \'gedung\'
            LEFT JOIN LATERAL (SELECT min(p2.mulai_pada) AS mulai_pertama
                FROM kunjungan.penempatan_tempat_tidur p1 JOIN kunjungan.penempatan_tempat_tidur p2 ON p2.kunjungan_id = p1.kunjungan_id
                WHERE p1.tempat_tidur_id = tt.id AND p1.selesai_pada IS NULL) pn ON true
            WHERE '.$saring.'
            ORDER BY length(gd.kode) NULLS LAST, gd.kode, gd.id, length(lt.kode) NULLS LAST, lt.kode, lt.id,
                length(b.kode), b.kode, length(tt.kode), tt.kode
            LIMIT ?', $ikatan);

        return [
            'baris' => array_values(array_map(fn (stdClass $b) => $this->bentuk($b), array_slice($hasil, 0, self::BATAS_BARIS))),
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

    /** @return array<string, mixed> */
    private function bentuk(stdClass $b): array
    {
        $ditutup = UbahStatusTempatTidur::ditutup($b->berlaku_sampai);
        $status = $ditutup ? StatusTempatTidur::TIDAK_DIPAKAI : (string) $b->status_saat_ini;
        $ringkas = fn (mixed $id, mixed $kode, mixed $nama) => $id === null ? null : ['id' => (string) $id, 'kode' => (string) $kode, 'nama' => (string) $nama];

        return [
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
            'hari_rawat' => $ditutup || $b->mulai_pertama === null ? null : BacaRawatInap::hariRawat((string) $b->mulai_pertama),
        ];
    }
}
