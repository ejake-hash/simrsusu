<?php

namespace App\Core\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Membaca dan memeriksa rantai hash audit.log_aktivitas (ADR 0003). Hash dihitung ulang dengan
 * fungsi SQL yang sama dengan trigger (audit.hash_rantai), jadi hanya ada satu definisi serialisasi.
 */
final class RantaiAudit
{
    public const HASH_AWAL = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Batas urutan untuk rentang waktu [dari, sampai). Null bila rentang kosong.
     *
     * @return array{0: int, 1: int}|null
     */
    public function batasUrutan(?CarbonImmutable $dari, ?CarbonImmutable $sampai): ?array
    {
        $saring = function ($q) use ($dari, $sampai) {
            if ($dari) {
                $q->where('terjadi_pada', '>=', $dari->utc()->format('Y-m-d H:i:s.uP'));
            }
            if ($sampai) {
                $q->where('terjadi_pada', '<', $sampai->utc()->format('Y-m-d H:i:s.uP'));
            }
        };

        $baris = DB::table('audit.log_aktivitas')->where($saring)
            ->selectRaw('min(urutan) AS awal, max(urutan) AS akhir')->first();

        return $baris->awal === null ? null : [(int) $baris->awal, (int) $baris->akhir];
    }

    /** Baris pertama dalam [awal, akhir] yang hash-nya tidak cocok, atau null bila utuh. */
    public function barisPutusPertama(int $awal, int $akhir): ?object
    {
        $sebelumnya = DB::table('audit.log_aktivitas')->where('urutan', '<', $awal)
            ->orderByDesc('urutan')->value('hash_rantai') ?? self::HASH_AWAL;

        return DB::selectOne(<<<'SQL'
            SELECT urutan, id, terjadi_pada
              FROM (
                SELECT l.urutan, l.id, l.terjadi_pada, l.hash_rantai,
                       audit.hash_rantai(coalesce(lag(l.hash_rantai::text) OVER (ORDER BY l.urutan), ?), l) AS harapan
                  FROM audit.log_aktivitas l
                 WHERE l.urutan BETWEEN ? AND ?
              ) r
             WHERE r.hash_rantai::text <> r.harapan
             ORDER BY r.urutan
             LIMIT 1
            SQL, [$sebelumnya, $awal, $akhir]);
    }

    public function jumlahBaris(int $awal, int $akhir): int
    {
        return DB::table('audit.log_aktivitas')->whereBetween('urutan', [$awal, $akhir])->count();
    }

    /** Baris terakhir rantai (urutan terbesar) yang terjadi sebelum batas. */
    public function kepalaSebelum(CarbonImmutable $batas): ?object
    {
        return DB::table('audit.log_aktivitas')
            ->where('terjadi_pada', '<', $batas->utc()->format('Y-m-d H:i:s.uP'))
            ->orderByDesc('urutan')
            ->first(['urutan', 'id', 'terjadi_pada', 'hash_rantai']);
    }

    public function barisDenganUrutan(int $urutan): ?object
    {
        return DB::table('audit.log_aktivitas')->where('urutan', $urutan)->first(['urutan', 'id', 'hash_rantai']);
    }
}
