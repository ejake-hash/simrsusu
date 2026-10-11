<?php

namespace Tests\Feature;

use Illuminate\Cache\RedisStore;
use Illuminate\Database\QueryException;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class InfrastrukturTest extends TestCase
{
    /** Tabel contoh dibuat oleh simrs_migrator agar hak bawaan per skema ikut teruji. */
    private const TABEL_CONTOH = ['inti.uji_hak_akses', 'audit.uji_hak_akses', 'analitik.uji_hak_akses', 'ttd.uji_hak_akses'];

    protected function setUp(): void
    {
        parent::setUp();

        $migrator = DB::connection('pgsql_migrator');
        foreach (self::TABEL_CONTOH as $tabel) {
            $migrator->statement("CREATE TABLE IF NOT EXISTS $tabel (id int PRIMARY KEY, isi text)");
        }

        // Dijalankan setelah rollback DatabaseTransactions, supaya DROP tidak menunggu kunci baris.
        $this->beforeApplicationDestroyed(function () use ($migrator) {
            foreach (self::TABEL_CONTOH as $tabel) {
                $migrator->statement("DROP TABLE IF EXISTS $tabel");
            }
            $migrator->disconnect();
        });
    }

    public function test_aplikasi_terhubung_sebagai_simrs_app(): void
    {
        $this->assertSame('simrs_app', DB::scalar('SELECT current_user'));
    }

    public function test_tabel_migrations_ada_di_skema_sistem(): void
    {
        $this->assertSame('sistem', DB::scalar(
            "SELECT schemaname FROM pg_tables WHERE tablename = 'migrations'"
        ));
    }

    public function test_tabel_bawaan_users_tidak_ada_dan_token_sanctum_di_sistem(): void
    {
        $this->assertSame(0, DB::scalar(
            "SELECT count(*) FROM pg_tables WHERE tablename IN ('users', 'password_reset_tokens', 'sessions')"
        ));
        $this->assertSame('uuid', DB::scalar(
            "SELECT data_type FROM information_schema.columns
              WHERE table_schema = 'sistem' AND table_name = 'personal_access_tokens' AND column_name = 'id'"
        ));
    }

    public function test_simrs_analitik_gagal_select_di_akses_ttd_audit_sistem(): void
    {
        // ttd.uji_hak_akses baru dibuat migrator, jadi hak bawaan (DEFAULT PRIVILEGES) ikut teruji.
        foreach (['akses.pengguna', 'ttd.uji_hak_akses', 'audit.log_aktivitas', 'sistem.migrations'] as $tabel) {
            $this->assertSame('42501', $this->selectSebagaiAnalitik($tabel), "simrs_analitik seharusnya ditolak di $tabel");
        }

        // Kontrol: skema domain biasa tetap bisa dibaca untuk REFRESH materialized view.
        $this->assertNull($this->selectSebagaiAnalitik('sdm.pegawai'));
    }

    public function test_skema_sistem_boleh_dml_penuh(): void
    {
        DB::table('cache')->insert(['key' => 'uji', 'value' => '1', 'expiration' => 0]);
        DB::table('cache')->where('key', 'uji')->update(['value' => '2']);

        $this->assertSame(1, DB::table('cache')->where('key', 'uji')->delete());
    }

    public function test_simrs_app_tidak_boleh_ddl(): void
    {
        $this->assertDitolak(fn () => DB::statement('CREATE TABLE sistem.uji_ddl (id int)'));
    }

    public function test_skema_domain_boleh_tambah_dan_ubah_tetapi_tidak_boleh_hapus(): void
    {
        DB::table('inti.uji_hak_akses')->insert(['id' => 1, 'isi' => 'awal']);
        DB::table('inti.uji_hak_akses')->where('id', 1)->update(['isi' => 'ubah']);
        $this->assertSame('ubah', DB::table('inti.uji_hak_akses')->where('id', 1)->value('isi'));

        $this->assertDitolak(fn () => DB::table('inti.uji_hak_akses')->where('id', 1)->delete());
    }

    public function test_skema_audit_hanya_boleh_tambah_dan_baca(): void
    {
        DB::table('audit.uji_hak_akses')->insert(['id' => 1, 'isi' => 'log']);
        $this->assertSame('log', DB::table('audit.uji_hak_akses')->where('id', 1)->value('isi'));

        $this->assertDitolak(fn () => DB::table('audit.uji_hak_akses')->where('id', 1)->update(['isi' => 'ubah']));
        $this->assertDitolak(fn () => DB::table('audit.uji_hak_akses')->where('id', 1)->delete());
    }

    public function test_skema_analitik_hanya_boleh_dibaca_aplikasi(): void
    {
        $this->assertSame(0, DB::table('analitik.uji_hak_akses')->count());

        $this->assertDitolak(fn () => DB::table('analitik.uji_hak_akses')->insert(['id' => 1, 'isi' => 'x']));
    }

    public function test_role_pendukung_ada_dan_belum_bisa_login(): void
    {
        $role = DB::table('pg_roles')
            ->whereIn('rolname', ['simrs_analitik', 'simrs_baca'])
            ->pluck('rolcanlogin', 'rolname')
            ->map(fn ($bisaLogin) => (bool) $bisaLogin)
            ->all();
        ksort($role);

        $this->assertSame(['simrs_analitik' => false, 'simrs_baca' => false], $role);
    }

    /** PRD 14.1.1 butir 2: query lambat gagal sendiri, bukan menggantung. Tes ini sengaja menunggu 5 detik. */
    public function test_query_lambat_simrs_app_dibatalkan_batas_waktu(): void
    {
        $this->assertSame('5s', DB::scalar('SHOW statement_timeout'));

        try {
            // Savepoint, agar pembatalan tidak menggagalkan transaksi tes.
            DB::transaction(fn () => DB::select('SELECT pg_sleep(6)'));
        } catch (QueryException $e) {
            $this->assertSame('57014', $e->getCode(), 'Seharusnya dibatalkan statement_timeout.');

            return;
        }

        $this->fail('pg_sleep(6) seharusnya dibatalkan setelah 5 detik.');
    }

    public function test_batas_waktu_simrs_analitik_lima_menit(): void
    {
        // Role NOLOGIN, jadi yang diperiksa setelannya (klaster atau database ini).
        $setelan = DB::table('pg_db_role_setting as s')
            ->join('pg_roles as r', 'r.oid', '=', 's.setrole')
            ->where('r.rolname', 'simrs_analitik')
            ->whereRaw('s.setdatabase IN (0, (SELECT oid FROM pg_database WHERE datname = current_database()))')
            ->pluck('s.setconfig')
            ->implode(',');

        $this->assertStringContainsString('statement_timeout=5min', $setelan);
    }

    public function test_valkey_tersedia(): void
    {
        $this->assertSame('PONG', (string) Redis::connection()->ping());
        $this->assertSame('PONG', (string) Redis::connection('cache')->ping());
        // Bentuk balasan INFO berbeda antara predis dan phpredis; cukup pastikan servernya Valkey.
        $this->assertStringContainsString('valkey_version', (string) json_encode(Redis::connection()->command('info', ['server'])));
    }

    /** PRD 14.1.1 butir 6: sesi dan queue di instans yang tidak pernah membuang data. */
    public function test_sesi_queue_dan_kunci_cache_di_instans_noeviction(): void
    {
        $sesi = app('session')->driver('redis')->getHandler();
        $this->assertInstanceOf(CacheBasedSessionHandler::class, $sesi);
        $storeSesi = $sesi->getCache()->getStore();
        $this->assertInstanceOf(RedisStore::class, $storeSesi);
        $this->assertSame('noeviction', $this->kebijakanMemori($storeSesi->connection()));

        $queue = app('queue')->connection('redis');
        $this->assertInstanceOf(RedisQueue::class, $queue);
        $this->assertSame('noeviction', $this->kebijakanMemori($queue->getConnection()));

        // Kunci (lock) cache tidak boleh hilang di tengah jalan, jadi ikut instans noeviction.
        $this->assertSame('noeviction', $this->kebijakanMemori($this->storeCacheRedis()->lockConnection()));
    }

    /**
     * PRD 14.1.1 butir 6–7: penghitung rate limit dan kunci nama fiktif (MasukPengguna) tidak boleh
     * dibuang saat memori cache penuh. Tes sendiri memakai store `array` (CACHE_LIMITER_STORE di
     * phpunit.xml), jadi yang diperiksa adalah nilai bawaan config/cache.php yang dipakai di luar tes.
     */
    public function test_penghitung_rate_limit_di_instans_noeviction(): void
    {
        $limiter = $this->tanpaEnv('CACHE_LIMITER_STORE', fn () => (require config_path('cache.php'))['limiter']);

        $store = Cache::store($limiter)->getStore();
        $this->assertInstanceOf(RedisStore::class, $store, "Store limiter '$limiter' harus di Valkey.");
        $this->assertSame('noeviction', $this->kebijakanMemori($store->connection()));
    }

    public function test_cache_di_instans_allkeys_lru(): void
    {
        $this->assertSame('allkeys-lru', $this->kebijakanMemori($this->storeCacheRedis()->connection()));
    }

    private function storeCacheRedis(): RedisStore
    {
        $store = Cache::store('redis')->getStore();
        $this->assertInstanceOf(RedisStore::class, $store);

        return $store;
    }

    /** Menjalankan $baca seolah variabel lingkungan $nama tidak diset, lalu memulihkannya. */
    private function tanpaEnv(string $nama, callable $baca): mixed
    {
        $semula = [getenv($nama), $_ENV[$nama] ?? null, $_SERVER[$nama] ?? null];
        putenv($nama);
        unset($_ENV[$nama], $_SERVER[$nama]);

        try {
            return $baca();
        } finally {
            [$env, $envSuper, $server] = $semula;
            if ($env !== false) {
                putenv("$nama=$env");
            }
            if ($envSuper !== null) {
                $_ENV[$nama] = $envSuper;
            }
            if ($server !== null) {
                $_SERVER[$nama] = $server;
            }
        }
    }

    private function kebijakanMemori(Connection $koneksi): string
    {
        $hasil = $koneksi->command('config', ['GET', 'maxmemory-policy']);

        // predis dan phpredis sama-sama mengembalikan ['maxmemory-policy' => '...'].
        return $hasil['maxmemory-policy'];
    }

    /** Mengembalikan SQLSTATE bila ditolak, null bila berhasil. Migrator anggota simrs_analitik. */
    private function selectSebagaiAnalitik(string $tabel): ?string
    {
        $migrator = DB::connection('pgsql_migrator');
        try {
            $migrator->transaction(function () use ($migrator, $tabel) {
                $migrator->statement('SET LOCAL ROLE simrs_analitik');
                $migrator->select("SELECT 1 FROM $tabel LIMIT 1");
            });
        } catch (QueryException $e) {
            return $e->getCode();
        }

        return null;
    }
}
