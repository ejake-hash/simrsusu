<?php

namespace App\Modules\Integrasi\Infrastructure;

/**
 * Port LZString 1.5.0 (pieroxy/lz-string, MIT) varian EncodedURIComponent, untuk respons VClaim terenkripsi (catatan
 * lapangan §1.3: AES-256-CBC lalu LZString). Bekerja atas unit UTF-16 seperti JavaScript; setiap unit disimpan sebagai
 * string 2 bita big-endian agar kunci kamus bisa digabung seperti string JS. Kompres hanya dipakai simulator dan tes.
 * Kecocokan dengan pustaka JS diuji dengan vektor dari lz-string 1.5.0.
 */
final class LzString
{
    private const KUNCI = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+-$';

    public static function kompres(string $teks): string
    {
        $unit = self::keUnit($teks);
        /** @var array<string, int> $kamus */
        $kamus = [];
        /** @var array<string, true> $buat */
        $buat = [];
        $w = '';
        $perbesar = 2;
        $ukuran = 3;
        $bit = 2;
        $keluar = '';
        $nilai = 0;
        $posisi = 0;
        $tulis = function (int $v, int $n) use (&$nilai, &$posisi, &$keluar): void {
            for ($i = 0; $i < $n; $i++) {
                $nilai = ($nilai << 1) | ($v & 1);
                if ($posisi === 5) {
                    $posisi = 0;
                    $keluar .= self::KUNCI[$nilai];
                    $nilai = 0;
                } else {
                    $posisi++;
                }
                $v >>= 1;
            }
        };
        $tulisW = function () use (&$w, &$kamus, &$buat, &$perbesar, &$bit, $tulis): void {
            if (isset($buat[$w])) {
                $kode = unpack('n', $w)[1];
                if ($kode < 256) {
                    $tulis(0, $bit);
                    $tulis($kode, 8);
                } else {
                    $tulis(1, $bit);
                    $tulis($kode, 16);
                }
                if (--$perbesar === 0) {
                    $perbesar = 2 ** $bit;
                    $bit++;
                }
                unset($buat[$w]);
            } else {
                $tulis($kamus[$w], $bit);
            }
            if (--$perbesar === 0) {
                $perbesar = 2 ** $bit;
                $bit++;
            }
        };

        foreach ($unit as $c) {
            if (! isset($kamus[$c])) {
                $kamus[$c] = $ukuran++;
                $buat[$c] = true;
            }
            $wc = $w.$c;
            if (isset($kamus[$wc])) {
                $w = $wc;

                continue;
            }
            $tulisW();
            $kamus[$wc] = $ukuran++;
            $w = $c;
        }
        if ($w !== '') {
            $tulisW();
        }
        $tulis(2, $bit);
        while (true) {
            $nilai <<= 1;
            if ($posisi === 5) {
                $keluar .= self::KUNCI[$nilai];
                break;
            }
            $posisi++;
        }

        return $keluar;
    }

    /** null = data rusak (sama dengan pustaka JS). */
    public static function dekompres(string $masukan): ?string
    {
        if ($masukan === '') {
            return null;
        }
        $masukan = str_replace(' ', '+', $masukan);
        $panjang = strlen($masukan);
        $basis = array_flip(str_split(self::KUNCI));
        $ambil = fn (int $i): int => $i < $panjang ? ($basis[$masukan[$i]] ?? 0) : 0;
        $data = ['nilai' => $ambil(0), 'posisi' => 32, 'indeks' => 1];
        $baca = function (int $n) use (&$data, $ambil): int {
            $bits = 0;
            for ($p = 0; $p < $n; $p++) {
                $resb = $data['nilai'] & $data['posisi'];
                $data['posisi'] >>= 1;
                if ($data['posisi'] === 0) {
                    $data['posisi'] = 32;
                    $data['nilai'] = $ambil($data['indeks']++);
                }
                $bits |= ($resb > 0 ? 1 : 0) << $p;
            }

            return $bits;
        };

        $kamus = [0 => '', 1 => '', 2 => ''];
        $perbesar = 4;
        $ukuran = 4;
        $bit = 3;
        $c = match ($baca(2)) {
            0 => pack('n', $baca(8)),
            1 => pack('n', $baca(16)),
            default => null,
        };
        if ($c === null) {
            return '';
        }
        $kamus[3] = $c;
        $w = $c;
        $hasil = $c;
        while (true) {
            if ($data['indeks'] > $panjang) {
                return '';
            }
            $kode = $baca($bit);
            if ($kode === 0 || $kode === 1) {
                $kamus[$ukuran++] = pack('n', $baca($kode === 0 ? 8 : 16));
                $kode = $ukuran - 1;
                $perbesar--;
            } elseif ($kode === 2) {
                return self::dariUnit($hasil);
            }
            if ($perbesar === 0) {
                $perbesar = 2 ** $bit;
                $bit++;
            }
            if (isset($kamus[$kode]) && $kode >= 3) {
                $entri = $kamus[$kode];
            } elseif ($kode === $ukuran) {
                $entri = $w.substr($w, 0, 2);
            } else {
                return null;
            }
            $hasil .= $entri;
            $kamus[$ukuran++] = $w.substr($entri, 0, 2);
            $perbesar--;
            $w = $entri;
            if ($perbesar === 0) {
                $perbesar = 2 ** $bit;
                $bit++;
            }
        }
    }

    /** @return list<string> unit UTF-16 (2 bita BE) */
    private static function keUnit(string $teks): array
    {
        $utf16 = mb_convert_encoding($teks, 'UTF-16BE', 'UTF-8');

        return $utf16 === '' ? [] : str_split($utf16, 2);
    }

    private static function dariUnit(string $utf16): string
    {
        return (string) mb_convert_encoding($utf16, 'UTF-8', 'UTF-16BE');
    }
}
