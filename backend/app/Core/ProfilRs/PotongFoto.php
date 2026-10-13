<?php

namespace App\Core\ProfilRs;

use App\Core\Http\ApiError;
use GdImage;

/**
 * Foto panel halaman masuk (tiket 1.15): bagian tengah dipotong 16:10 lalu dienkode ulang dengan tipe yang sama. Enkode
 * ulang membuang semua metadata (EXIF, GPS, XMP); orientasi EXIF JPEG diterapkan lebih dulu agar foto ponsel tidak miring.
 */
final class PotongFoto
{
    private const RASIO = 16 / 10;

    private const MUTU = 85;

    public static function potong(string $isi, string $tipe): string
    {
        $gambar = @imagecreatefromstring($isi);
        if (! $gambar instanceof GdImage) {
            throw new ApiError(422, 'tipe_berkas_tidak_diizinkan', 'Gambar tidak bisa dibaca.', [['kolom' => 'berkas', 'pesan' => 'Berkas gambar rusak.']]);
        }
        if ($tipe === 'image/jpeg') {
            $gambar = self::tegakkan($gambar, $isi);
        }

        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);
        [$w, $h] = $lebar / $tinggi > self::RASIO
            ? [(int) round($tinggi * self::RASIO), $tinggi]
            : [$lebar, (int) round($lebar / self::RASIO)];
        $hasil = imagecrop($gambar, ['x' => intdiv($lebar - $w, 2), 'y' => intdiv($tinggi - $h, 2), 'width' => $w, 'height' => $h]);
        if (! $hasil instanceof GdImage) {
            throw new ApiError(422, 'tipe_berkas_tidak_diizinkan', 'Gambar tidak bisa diproses.', [['kolom' => 'berkas', 'pesan' => 'Berkas gambar rusak.']]);
        }

        ob_start();
        match ($tipe) {
            'image/jpeg' => imagejpeg($hasil, null, self::MUTU),
            'image/webp' => imagewebp($hasil, null, self::MUTU),
            default => (imagesavealpha($hasil, true) && imagepng($hasil)),
        };

        return (string) ob_get_clean();
    }

    /** Orientasi EXIF 3/6/8 (diputar) diterapkan; cermin (2/4/5/7) jarang di kamera dan diabaikan. */
    private static function tegakkan(GdImage $gambar, string $isi): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $gambar;
        }
        // Lewat stream memori: tidak bergantung allow_url_fopen (data://) dan tidak menulis berkas sementara.
        $aliran = fopen('php://memory', 'r+b');
        if ($aliran === false) {
            return $gambar;
        }
        fwrite($aliran, $isi);
        rewind($aliran);
        $exif = @exif_read_data($aliran);
        fclose($aliran);
        $sudut = match (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        $diputar = $sudut === 0 ? $gambar : imagerotate($gambar, $sudut, 0);

        return $diputar instanceof GdImage ? $diputar : $gambar;
    }
}
