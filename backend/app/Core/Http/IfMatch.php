<?php

namespace App\Core\Http;

use Illuminate\Http\Request;

/** Versi dari header If-Match (penguncian optimistis, kontrak: 428 bila tidak ada, 412 bila berubah). */
final class IfMatch
{
    /**
     * Nilai tanpa tanda kutip, awalan W/, dan akhiran bentuk ETag (mis. "-r" untuk bentuk ber-rinci).
     */
    public static function versi(Request $request): string
    {
        $nilai = trim((string) preg_replace('#^W/#', '', trim((string) $request->header('If-Match'))), '"');
        if ($nilai === '') {
            throw new ApiError(428, 'if_match_wajib', 'Versi data tidak dikirim. Muat ulang halaman lalu coba lagi.');
        }

        return (string) preg_replace('/-[a-z]+$/', '', $nilai);
    }

    /** Versi berupa revisi integer: angka di luar rentang tidak mungkin cocok. */
    public static function revisi(string $versi): ?int
    {
        return ctype_digit($versi) && strlen($versi) <= 9 ? (int) $versi : null;
    }
}
