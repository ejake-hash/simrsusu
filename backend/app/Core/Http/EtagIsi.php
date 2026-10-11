<?php

namespace App\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ETag = sidik isi respons untuk polling (K1): If-None-Match yang sama → 304 tanpa isi. Karena dari isi, nilai yang
 * berubah karena waktu (mis. penanda terlambat) ikut mengubah ETag.
 */
final class EtagIsi
{
    public static function terapkan(Request $request, JsonResponse $respons): Response
    {
        $etag = '"'.sha1((string) $respons->getContent()).'"';
        $respons->headers->set('ETag', $etag);
        $respons->setPrivate();
        // Proksi yang mengompres respons mengubah ETag kuat menjadi W/"…": bandingkan tanpa awalan itu.
        $diminta = array_map(fn (string $e) => str_starts_with($e, 'W/') ? substr($e, 2) : $e, $request->getETags());
        if (in_array($etag, $diminta, true)) {
            $kosong = new Response('', 304);
            $kosong->headers->set('ETag', $etag);
            $kosong->setPrivate();

            return $kosong;
        }

        return $respons;
    }

    /** @param  array<string, mixed>  $meta */
    public static function tambahMeta(JsonResponse $respons, array $meta): JsonResponse
    {
        $isi = $respons->getData(true);
        $isi['meta'] = [...($isi['meta'] ?? []), ...$meta];

        return $respons->setData($isi);
    }
}
