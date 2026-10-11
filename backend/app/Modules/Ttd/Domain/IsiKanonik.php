<?php

namespace App\Modules\Ttd\Domain;

use Illuminate\Support\Carbon;

/**
 * Isi kanonik (PRD 7.57.2 huruf c): JSON dengan kunci terurut di setiap tingkat, tanpa spasi, UTF-8 apa adanya,
 * lalu SHA-256. Pesan yang ditandatangani mengikat jenis + id objek, hash isi, cap waktu, dan akun penanda tangan,
 * sehingga tanda tangan tidak bisa dipindah ke objek, waktu, atau orang lain.
 */
final class IsiKanonik
{
    /** @param  array<string, mixed>  $isi */
    public static function json(array $isi): string
    {
        return (string) json_encode(self::urutkan($isi), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $isi */
    public static function hash(array $isi): string
    {
        return hash('sha256', self::json($isi));
    }

    public static function pesan(string $objekJenis, string $objekId, string $hashIsi, Carbon $capWaktu, string $penggunaId): string
    {
        return implode("\n", ['SIMRS-TTD-T1', $objekJenis, $objekId, $hashIsi, self::waktu($capWaktu), $penggunaId]);
    }

    /** Waktu kanonik: UTC, mikrodetik (sama dengan presisi timestamptz). */
    public static function waktu(?Carbon $waktu): ?string
    {
        return $waktu?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function urutkan(mixed $nilai): mixed
    {
        if (! is_array($nilai)) {
            return $nilai;
        }
        if (! array_is_list($nilai)) {
            ksort($nilai, SORT_STRING);
        }

        return array_map(self::urutkan(...), $nilai);
    }
}
