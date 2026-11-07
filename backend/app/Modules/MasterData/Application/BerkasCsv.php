<?php

namespace App\Modules\MasterData\Application;

use App\Core\Http\ApiError;

/**
 * Pembaca CSV impor data induk (aturan berkas kontrak POST /lokasi/impor): UTF-8, BOM dibuang, pemisah `,` atau `;`
 * (Excel ber-locale Indonesia), judul kolom persis, paling banyak 5.000 baris data. 422 `berkas_tidak_valid` selain itu.
 */
final class BerkasCsv
{
    public const MAKS_BARIS = 5000;

    public const MAKS_KESALAHAN = 500;

    /**
     * @param  list<string>  $kolom  judul kolom baku, urutan sama
     * @return array<int, array<string, string>> nomor baris berkas (judul = 1) => kolom => nilai ter-trim
     */
    public static function baca(string $isi, array $kolom): array
    {
        $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi) ?? '';
        if (! mb_check_encoding($isi, 'UTF-8') || str_contains($isi, "\0")) {
            throw self::tidakValid('Berkas harus CSV berenkode UTF-8.');
        }
        $aliran = fopen('php://memory', 'r+');
        fwrite($aliran, $isi);
        rewind($aliran);
        $barisPertama = strtok($isi, "\n");
        $pemisah = is_string($barisPertama) && str_contains($barisPertama, ';') && ! str_contains($barisPertama, ',') ? ';' : ',';
        $judul = fgetcsv($aliran, separator: $pemisah, escape: '');
        if ($judul === false || array_map(fn ($k) => trim((string) $k), $judul) !== $kolom) {
            fclose($aliran);
            throw self::tidakValid('Judul kolom harus: '.implode(',', $kolom).'.');
        }
        $baris = [];
        $nomor = 1;
        while (($nilai = fgetcsv($aliran, separator: $pemisah, escape: '')) !== false) {
            $nomor++;
            if ($nilai === [null]) {
                continue;
            }
            if (count($baris) >= self::MAKS_BARIS) {
                fclose($aliran);
                throw self::tidakValid('Paling banyak '.self::MAKS_BARIS.' baris data per berkas; pecah berkasnya.');
            }
            $baris[$nomor] = array_combine($kolom, array_map(fn ($n) => trim((string) $n), array_pad(array_slice($nilai, 0, count($kolom)), count($kolom), '')));
        }
        fclose($aliran);
        if ($baris === []) {
            throw self::tidakValid('Berkas tidak berisi baris data.');
        }

        return $baris;
    }

    /**
     * HasilImpor (kontrak v0.23.0); kesalahan dipotong 500.
     *
     * @param  list<array{baris: int, kolom: string|null, kode: string, pesan: string}>  $kesalahan
     * @return array<string, mixed>
     */
    public static function hasil(string $mode, bool $diterapkan, int $jumlahBaris, array $kesalahan): array
    {
        return [
            'mode' => $mode, 'diterapkan' => $diterapkan, 'jumlah_baris' => $jumlahBaris,
            'jumlah_salah' => count(array_unique(array_column($kesalahan, 'baris'))),
            'kesalahan' => array_slice($kesalahan, 0, self::MAKS_KESALAHAN), 'kesalahan_terpotong' => count($kesalahan) > self::MAKS_KESALAHAN,
        ];
    }

    /** `ya` | `tidak` | kosong (= tidak); selain itu null. */
    public static function yaTidak(string $nilai): ?bool
    {
        return match (mb_strtolower($nilai)) {
            'ya' => true, 'tidak', '' => false, default => null,
        };
    }

    private static function tidakValid(string $pesan): ApiError
    {
        return new ApiError(422, 'berkas_tidak_valid', $pesan, [['kolom' => 'berkas', 'pesan' => $pesan]]);
    }
}
