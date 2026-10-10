<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Tes berjalan sebagai simrs_app yang tidak boleh DDL, jadi skema disiapkan
 * lewat `composer test` (migrasi sebagai simrs_migrator) dan setiap tes
 * dibungkus transaksi. Jangan pakai RefreshDatabase; CI menolaknya.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;
}
