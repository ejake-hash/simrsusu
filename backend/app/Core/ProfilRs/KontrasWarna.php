<?php

namespace App\Core\ProfilRs;

/**
 * Uji kontras warna paket identitas (FR-54-08 langkah 2, PRD 5.3.2), rumus WCAG 2.1 (luminans relatif).
 * - brand: teks/tautan di latar putih dan latar tombol bertulisan putih → kontras dengan putih ≥ 4,5:1.
 * - brand: sekaligus memenuhi "isian ≥ 3:1".
 * - accent: isian dekoratif saja, tidak diuji terhadap putih; teks di atasnya bertinta --text (#1D1D1B) → ≥ 4,5:1.
 * Tafsiran FR-54-08 ini tercatat di kontrak v0.3.0 dan menunggu konfirmasi pemilik produk.
 */
final class KontrasWarna
{
    public const MINIMAL_BRAND = 4.5;

    public const MINIMAL_ACCENT = 4.5;

    private const PUTIH = '#FFFFFF';

    private const TEKS = '#1D1D1B';

    /**
     * @param  array{brand: string, accent: string}  $warna
     * @return list<array{kolom: string, rasio: float, minimal: float, pesan: string}>
     */
    public static function periksa(array $warna): array
    {
        $kurang = [];
        $brand = self::rasio($warna['brand'], self::PUTIH);
        if ($brand < self::MINIMAL_BRAND) {
            $kurang[] = ['kolom' => 'warna.brand', 'rasio' => round($brand, 2), 'minimal' => self::MINIMAL_BRAND,
                'pesan' => sprintf('Kontras warna utama dengan putih %s:1, minimal 4,5:1.', self::angka($brand))];
        }
        $accent = self::rasio($warna['accent'], self::TEKS);
        if ($accent < self::MINIMAL_ACCENT) {
            $kurang[] = ['kolom' => 'warna.accent', 'rasio' => round($accent, 2), 'minimal' => self::MINIMAL_ACCENT,
                'pesan' => sprintf('Kontras teks gelap di atas warna aksen %s:1, minimal 4,5:1.', self::angka($accent))];
        }

        return $kurang;
    }

    public static function rasio(string $a, string $b): float
    {
        [$terang, $gelap] = [max(self::luminans($a), self::luminans($b)), min(self::luminans($a), self::luminans($b))];

        return ($terang + 0.05) / ($gelap + 0.05);
    }

    private static function luminans(string $hex): float
    {
        $kanal = array_map(function (string $dua) {
            $c = hexdec($dua) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $kanal[0] + 0.7152 * $kanal[1] + 0.0722 * $kanal[2];
    }

    private static function angka(float $rasio): string
    {
        return str_replace('.', ',', (string) round($rasio, 2));
    }
}
