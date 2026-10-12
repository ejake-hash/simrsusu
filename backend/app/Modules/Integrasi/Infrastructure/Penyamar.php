<?php

namespace App\Modules\Integrasi\Infrastructure;

/**
 * Penyamaran isi log integrasi (PRD 14.2, kontrak v0.13.0). Isi JSON memakai daftar kunci yang boleh tampil
 * (allowlist): nilai di bawah kunci lain diganti `***`. Teks yang tetap tampil (pesan, nilai kunci yang diizinkan,
 * path) juga dibersihkan dari deret angka panjang (NIK, nomor kartu, RM, rujukan/SEP, telepon). Nama, tanggal lahir,
 * alamat, token, dan kredensial tidak pernah ada di allowlist.
 */
final class Penyamar
{
    /** Kunci (huruf kecil) yang nilainya boleh tampil: struktur respons, kode status, dan pesan sistem luar. */
    private const BOLEH = [
        'metadata', 'code', 'message', 'response', 'list', 'kode', 'kodekelas', 'status', 'resourcetype', 'id', 'issue',
        'severity', 'details', 'diagnostics', 'coding', 'system', 'error', 'error_description', 'expires_in', 'token_type',
        'total', 'type', 'entry', 'resource', 'meta', 'lastupdated', 'versionid', 'physicaltype', 'mode', 'partof',
    ];

    public const TERSAMAR = '***';

    /** Deret angka 8 digit atau lebih (NIK 16, kartu 13, RM, nomor rujukan/SEP, telepon). */
    private const POLA_ANGKA = '/\d[\d\- ]{6,}\d/';

    public static function teks(?string $teks): ?string
    {
        return $teks === null ? null : (string) preg_replace(self::POLA_ANGKA, self::TERSAMAR, $teks);
    }

    /** Path tanpa query; segmen yang memuat deret angka panjang diganti. */
    public static function path(string $path): string
    {
        $path = (string) strtok($path, '?');

        return implode('/', array_map(fn (string $s) => preg_match(self::POLA_ANGKA, $s) === 1 ? self::TERSAMAR : $s, explode('/', $path)));
    }

    /** Isi permintaan/respons: JSON disamarkan per kunci; teks lain hanya dibersihkan deret angkanya. */
    public static function isi(mixed $isi): ?string
    {
        if ($isi === null || $isi === '' || $isi === []) {
            return null;
        }
        if (is_string($isi)) {
            $json = json_decode($isi, true);
            if (! is_array($json)) {
                // Bukan JSON (mis. respons BPJS terenkripsi atau halaman HTML error): tidak ditampilkan isinya.
                return '['.strlen($isi).' bita bukan JSON]';
            }
            $isi = $json;
        }

        return (string) json_encode(self::samarkan($isi, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function samarkan(mixed $nilai, bool $boleh): mixed
    {
        if (is_array($nilai)) {
            $hasil = [];
            foreach ($nilai as $k => $v) {
                $hasil[$k] = self::samarkan($v, is_int($k) ? $boleh : in_array(strtolower((string) $k), self::BOLEH, true));
            }

            return $hasil;
        }
        if ($nilai === null || is_bool($nilai)) {
            return $nilai;
        }
        if (! $boleh) {
            return self::TERSAMAR;
        }

        return is_string($nilai) ? self::teks($nilai) : $nilai;
    }
}
