<?php

namespace App\Core\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Karakter NUL (U+0000) di isian ditolak 422 di depan: PostgreSQL menolak NUL di kolom text, sehingga tanpa ini
 * permintaan berakhir 500 atau teks terpotong diam-diam. Berkas unggahan diperiksa pemrosesnya masing-masing.
 */
final class TolakKarakterNul
{
    public function handle(Request $request, Closure $next): Response
    {
        $kolom = self::cari([...$request->query(), ...$request->post(), ...($request->isJson() ? (array) $request->json()->all() : [])]);
        if ($kolom !== null) {
            throw new ApiError(422, 'validasi_gagal', 'Isian memuat karakter yang tidak diizinkan.', [['kolom' => $kolom, 'pesan' => 'Hapus karakter kendali dari isian.']]);
        }

        return $next($request);
    }

    /** @param  array<array-key, mixed>  $isi */
    private static function cari(array $isi, string $awalan = ''): ?string
    {
        foreach ($isi as $kunci => $nilai) {
            $nama = $awalan === '' ? (string) $kunci : "$awalan.$kunci";
            if (is_string($nilai) && str_contains($nilai, "\0") || is_string($kunci) && str_contains($kunci, "\0")) {
                return str_replace("\0", '', $nama);
            }
            if (is_array($nilai) && ($dalam = self::cari($nilai, $nama)) !== null) {
                return $dalam;
            }
        }

        return null;
    }
}
