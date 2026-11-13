<?php

namespace App\Modules\Lab\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penanda hasil lab dari data induk aktif (tiket 2.31, FR-19-06). Tidak ada angka di kode: batas rujukan/kritis hanya dari
 * `lab.nilai_rujukan` dan nilai kritis teks dari `lab.pemeriksaan`. Tanpa baris yang cocok → penanda null + peringatan.
 *
 * Pemilihan baris nilai rujukan: pemeriksaan sama, aktif, umur pasien (hari, tanggal lahir → tanggal spesimen diambil,
 * zona RS) di [umur_min_hari, umur_maks_hari), JK pasien didahulukan lalu baris JK null (semua). Pasien tanpa tanggal lahir
 * hanya cocok dengan baris tanpa batas umur (0 sampai tanpa batas). Tanggal lahir sesudah tanggal spesimen → tanpa rujukan.
 */
final class HitungPenanda
{
    public const PERINGATAN_TANPA_RUJUKAN = 'nilai_rujukan_tidak_ada';

    public const PENANDA_KRITIS = ['ll', 'hh', 'kritis'];

    /**
     * @param  object  $pemeriksaan  baris lab.pemeriksaan (id, jenis_hasil, nilai_normal_teks, nilai_kritis_teks)
     * @return array{nilai_rujukan_id: string|null, rujukan_min: string|null, rujukan_maks: string|null, kritis_min: string|null,
     *     kritis_maks: string|null, penanda: string|null, peringatan: string|null}
     */
    public function hitung(object $pemeriksaan, ?string $tanggalLahir, string $jenisKelamin, string $tanggalSpesimen, ?float $nilai, ?string $nilaiTeks): array
    {
        $hasil = ['nilai_rujukan_id' => null, 'rujukan_min' => null, 'rujukan_maks' => null, 'kritis_min' => null, 'kritis_maks' => null,
            'penanda' => null, 'peringatan' => null];

        if ($pemeriksaan->jenis_hasil === 'teks') {
            $kritis = $this->daftar($pemeriksaan->nilai_kritis_teks);
            if ($kritis === null && $this->daftar($pemeriksaan->nilai_normal_teks) === null) {
                return [...$hasil, 'peringatan' => self::PERINGATAN_TANPA_RUJUKAN];
            }
            $cocok = $nilaiTeks !== null && in_array(mb_strtolower(trim($nilaiTeks)), array_map(fn ($v) => mb_strtolower(trim((string) $v)), $kritis ?? []), true);

            return [...$hasil, 'penanda' => $cocok ? 'kritis' : null];
        }

        $r = $this->rujukan((string) $pemeriksaan->id, $tanggalLahir, $jenisKelamin, $tanggalSpesimen);
        if ($r === null || ($r->rujukan_min === null && $r->rujukan_maks === null && $r->kritis_min === null && $r->kritis_maks === null)) {
            return [...$hasil, 'peringatan' => self::PERINGATAN_TANPA_RUJUKAN];
        }
        $n = (float) $nilai;
        $penanda = match (true) {
            $r->kritis_min !== null && $n < (float) $r->kritis_min => 'll',
            $r->kritis_maks !== null && $n > (float) $r->kritis_maks => 'hh',
            $r->rujukan_min !== null && $n < (float) $r->rujukan_min => 'l',
            $r->rujukan_maks !== null && $n > (float) $r->rujukan_maks => 'h',
            default => null,
        };

        return ['nilai_rujukan_id' => (string) $r->id, 'rujukan_min' => $this->angka($r->rujukan_min), 'rujukan_maks' => $this->angka($r->rujukan_maks),
            'kritis_min' => $this->angka($r->kritis_min), 'kritis_maks' => $this->angka($r->kritis_maks), 'penanda' => $penanda, 'peringatan' => null];
    }

    public static function kritis(?string $penanda): bool
    {
        return in_array($penanda, self::PENANDA_KRITIS, true);
    }

    /** Tanggal spesimen diambil (zona RS) dari jam server. */
    public static function tanggalSpesimen(string $diambilPada): string
    {
        return Carbon::parse($diambilPada)->setTimezone((string) config('instalasi.zona_waktu'))->toDateString();
    }

    private function rujukan(string $pemeriksaanId, ?string $tanggalLahir, string $jenisKelamin, string $tanggalSpesimen): ?object
    {
        // Selisih bertanda: tanggal lahir sesudah tanggal spesimen = data tidak sah → tanpa rujukan (penanda null + peringatan).
        $umur = $tanggalLahir === null ? null
            : (int) round(Carbon::parse(substr($tanggalLahir, 0, 10))->startOfDay()->diffInDays(Carbon::parse($tanggalSpesimen)->startOfDay(), false));
        if ($umur !== null && $umur < 0) {
            return null;
        }

        return DB::table('lab.nilai_rujukan')->where('pemeriksaan_id', $pemeriksaanId)->where('status', 'aktif')
            ->where(fn ($q) => $q->whereNull('jenis_kelamin')->orWhere('jenis_kelamin', $jenisKelamin))
            ->when($umur === null, fn ($q) => $q->where('umur_min_hari', 0)->whereNull('umur_maks_hari'))
            ->when($umur !== null, fn ($q) => $q->where('umur_min_hari', '<=', $umur)
                ->where(fn ($q) => $q->whereNull('umur_maks_hari')->orWhere('umur_maks_hari', '>', $umur)))
            ->orderByRaw('jenis_kelamin IS NULL')->orderBy('id')
            ->first(['id', 'rujukan_min', 'rujukan_maks', 'kritis_min', 'kritis_maks']);
    }

    /** @return list<mixed>|null */
    private function daftar(?string $json): ?array
    {
        $isi = $json === null ? null : json_decode($json, true);

        return is_array($isi) && $isi !== [] ? array_values($isi) : null;
    }

    private function angka(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }
}
