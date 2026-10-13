<?php

namespace App\Core\ProfilRs;

/**
 * Teks panel halaman masuk hanya teks biasa (tiket 1.15): karakter kendali dibuang, spasi dan baris baru diringkas
 * menjadi satu spasi, tepi dipangkas. HTML/markdown tidak ditafsirkan (disimpan dan ditampilkan sebagai teks); panjang
 * diperiksa sesudah dibersihkan.
 */
final class TeksHalamanMasuk
{
    /**
     * @param  mixed  $isian  `halaman_masuk` dari permintaan; selain array dikembalikan apa adanya untuk ditolak validasi
     */
    public static function bersihkan(mixed $isian): mixed
    {
        if (! is_array($isian)) {
            return $isian;
        }
        foreach (['lencana', 'judul', 'judul_sorot', 'uraian', 'foto_keterangan'] as $kunci) {
            if (array_key_exists($kunci, $isian)) {
                $isian[$kunci] = self::teks($isian[$kunci]);
            }
        }
        // Kosong sesudah dibersihkan = tidak ada.
        foreach (['judul_sorot', 'foto_keterangan'] as $kunci) {
            if (($isian[$kunci] ?? null) === '') {
                $isian[$kunci] = null;
            }
        }
        if (isset($isian['fitur']) && is_array($isian['fitur'])) {
            $isian['fitur'] = array_map(fn ($f) => is_array($f) ? array_map(self::teks(...), $f) : $f, $isian['fitur']);
        }

        return $isian;
    }

    private static function teks(mixed $nilai): mixed
    {
        if (! is_string($nilai)) {
            return $nilai;
        }
        // Spasi Unicode (termasuk baris baru, tab, pemisah baris/paragraf) → satu spasi; sisa karakter kendali dan format
        // tak terlihat (mis. zero-width, arah teks) dibuang.
        $nilai = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $nilai);

        return trim((string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $nilai));
    }
}
