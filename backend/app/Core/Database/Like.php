<?php

namespace App\Core\Database;

/** Pola LIKE/ILIKE dari masukan pengguna: wildcard (`%`, `_`) dan `\` dibaca sebagai huruf biasa. */
final class Like
{
    public static function memuat(string $teks): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $teks).'%';
    }
}
