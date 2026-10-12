<?php

namespace App\Modules\Integrasi\Infrastructure;

use App\Modules\Integrasi\Domain\SistemIntegrasi;

/**
 * Penyamaran isi log integrasi (PRD 14.2, kontrak v0.13.0). Isi JSON memakai daftar kunci yang boleh tampil
 * (allowlist): nilai di bawah kunci lain diganti `***`. Teks yang tetap tampil (pesan, nilai kunci yang diizinkan,
 * path) juga dibersihkan dari deret angka panjang (NIK, nomor kartu, RM, rujukan/SEP, telepon). Nama, tanggal lahir,
 * alamat, token, dan kredensial tidak pernah ada di allowlist.
 */
final class Penyamar
{
    /** Kunci (huruf kecil) yang nilainya boleh tampil di semua sistem: struktur respons, kode status, pesan sistem luar. */
    private const BOLEH_UMUM = ['metadata', 'code', 'message', 'response', 'list', 'status', 'error', 'error_description',
        'expires_in', 'token_type', 'total', 'type'];

    /** Tambahan per sistem (kontrak v0.13.0: allowlist per sistem). */
    private const BOLEH_SISTEM = [
        'bpjs_vclaim' => ['kode'],
        'aplicares' => ['kode', 'kodekelas'],
        'satusehat' => ['resourcetype', 'id', 'issue', 'severity', 'details', 'diagnostics', 'coding', 'system', 'entry',
            'resource', 'meta', 'lastupdated', 'versionid', 'physicaltype', 'mode', 'partof'],
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

    /**
     * Isi permintaan/respons: JSON disamarkan per kunci (allowlist umum + sistem itu); teks bukan JSON tidak ditampilkan.
     * Sisa risiko: teks bebas di kunci yang diizinkan (message, diagnostics) hanya dibersihkan deret angkanya; nama orang
     * di dalamnya tidak bisa dikenali otomatis.
     */
    public static function isi(mixed $isi, ?SistemIntegrasi $sistem = null): ?string
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

        $boleh = [...self::BOLEH_UMUM, ...($sistem === null ? [] : self::BOLEH_SISTEM[$sistem->value])];

        return (string) json_encode(self::samarkan($isi, true, $boleh), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param  list<string>  $daftar */
    private static function samarkan(mixed $nilai, bool $boleh, array $daftar): mixed
    {
        if (is_array($nilai)) {
            $hasil = [];
            foreach ($nilai as $k => $v) {
                $hasil[$k] = self::samarkan($v, is_int($k) ? $boleh : in_array(strtolower((string) $k), $daftar, true), $daftar);
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
