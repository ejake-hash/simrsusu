<?php

namespace App\Modules\Integrasi\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sewa atomik per agregat di `integrasi.sewa_agregat`: satu pernyataan per ambil/lepas (autocommit), jadi aman untuk
 * PgBouncer mode transaksi (PRD 14.1.1), tidak seperti kunci advisory tingkat sesi. Sewa kedaluwarsa sendiri bila
 * pekerja mati.
 */
final class SewaAgregat
{
    /** @return string|null token pemegang, null bila sedang disewa pihak lain */
    public function ambil(string $kunci, int $menit = 10): ?string
    {
        $token = (string) Str::uuid7();
        $dapat = DB::selectOne(<<<'SQL'
            INSERT INTO integrasi.sewa_agregat (kunci, pemegang, disewa_sampai) VALUES (?, ?, now() + make_interval(mins => ?))
            ON CONFLICT (kunci) DO UPDATE SET pemegang = EXCLUDED.pemegang, disewa_sampai = EXCLUDED.disewa_sampai
            WHERE integrasi.sewa_agregat.disewa_sampai < now()
            RETURNING pemegang
            SQL, [$kunci, $token, $menit]);

        return $dapat === null ? null : $token;
    }

    public function lepas(string $kunci, string $token): void
    {
        DB::table('integrasi.sewa_agregat')->where('kunci', $kunci)->where('pemegang', $token)->update(['disewa_sampai' => DB::raw("now() - interval '1 second'")]);
    }
}
