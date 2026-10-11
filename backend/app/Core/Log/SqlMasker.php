<?php

namespace App\Core\Log;

use Illuminate\Database\QueryException;
use PDOException;
use ReflectionProperty;
use Throwable;

/**
 * Membuang nilai dari error basis data sebelum tercatat atau dirender (PRD 14.2, Data:
 * "data pribadi disamarkan di log"). SQL tetap tercatat dengan placeholder `?`.
 *
 * Nilai bocor di tiga tempat: binding yang disisipkan Laravel ke "SQL: ..." di pesan
 * QueryException, teks error PostgreSQL (DETAIL berisi baris data, nilai bertanda kutip), dan
 * PDOException::$errorInfo. Exception dibersihkan di tempat (reflection) agar jejak tumpukan
 * tetap utuh dan semua saluran pelapor menerima versi yang sama.
 */
final class SqlMasker
{
    public const PENANDA = '[nilai disamarkan]';

    public static function samarkanException(Throwable $e): void
    {
        for ($saat = $e; $saat !== null; $saat = $saat->getPrevious()) {
            if ($saat instanceof QueryException) {
                self::setel($saat, 'message', sprintf(
                    '%s (Connection: %s, SQL: %s) %s',
                    self::samarkanTeksError((string) $saat->getPrevious()?->getMessage()),
                    $saat->getConnectionName(),
                    $saat->getSql(),
                    self::PENANDA,
                ));
                self::setel($saat, 'bindings', array_fill(0, count($saat->getBindings()), '?'));
            } elseif ($saat instanceof PDOException) {
                self::setel($saat, 'message', self::samarkanTeksError($saat->getMessage()));
                if (is_array($saat->errorInfo) && isset($saat->errorInfo[2])) {
                    $saat->errorInfo[2] = self::samarkanTeksError((string) $saat->errorInfo[2]);
                }
            } elseif (str_contains($saat->getMessage(), 'SQLSTATE[')) {
                self::setel($saat, 'message', self::samarkanTeks($saat->getMessage()));
            }
        }
    }

    /**
     * Teks bebas (mis. pesan log yang disusun sendiri dari $e->getMessage()). Bila memuat bagian
     * "SQL: ..." yang belum disamarkan, SQL-nya dibuang seluruhnya karena nilainya sudah tersisip.
     */
    public static function samarkanTeks(string $teks): string
    {
        if (! str_contains($teks, 'SQLSTATE[') || str_contains($teks, self::PENANDA)) {
            return $teks;
        }

        $teks = preg_replace('/\(Connection: ([^,)]+).*?, SQL: .*\)\s*$/s', '(Connection: $1, SQL: '.self::PENANDA.')', $teks);

        return self::samarkanTeksError($teks);
    }

    /** Teks error PostgreSQL/PDO: hanya baris pertama, nilai bertanda kutip diganti `?`. */
    public static function samarkanTeksError(string $teks): string
    {
        [$baris] = explode("\n", $teks, 2);
        $sisa = str_contains($teks, "\n") && preg_match('/\(Connection: .*\)\s*$/s', $teks, $m) ? ' '.$m[0] : '';

        $baris = preg_replace('/(:\s*|\bvalue\s+)"(?:[^"]|"")*"/', '$1"?"', $baris);
        $baris = preg_replace("/'(?:[^']|'')*'/", "'?'", $baris);

        return $baris.$sisa;
    }

    private static function setel(Throwable $e, string $properti, mixed $nilai): void
    {
        $kelas = new \ReflectionClass($e);
        while (! $kelas->hasProperty($properti) && $kelas->getParentClass()) {
            $kelas = $kelas->getParentClass();
        }
        (new ReflectionProperty($kelas->getName(), $properti))->setValue($e, $nilai);
    }
}
