<?php

namespace Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Tes berjalan sebagai simrs_app yang tidak boleh DDL, jadi skema disiapkan
 * lewat `composer test` (migrasi sebagai simrs_migrator) dan setiap tes
 * dibungkus transaksi. Jangan pakai RefreshDatabase; CI menolaknya.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /**
     * Pastikan perintah ditolak karena kurang hak (SQLSTATE 42501). Dibungkus savepoint
     * agar transaksi tes tidak ikut batal dan perintah berikutnya tetap bisa jalan.
     */
    protected function assertDitolak(callable $perintah): void
    {
        try {
            DB::transaction($perintah);
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->getCode(), $e->getMessage());

            return;
        }

        $this->fail('Perintah seharusnya ditolak, tetapi berhasil.');
    }
}
