<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class InfrastrukturTest extends TestCase
{
    /** Tabel contoh dibuat oleh simrs_migrator agar hak bawaan per skema ikut teruji. */
    private const TABEL_CONTOH = ['inti.uji_hak_akses', 'audit.uji_hak_akses', 'analitik.uji_hak_akses'];

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

    public function test_simrs_analitik_tidak_bisa_membaca_skema_akses(): void
    {
        $this->assertFalse(DB::scalar("SELECT has_table_privilege('simrs_analitik', 'akses.pengguna', 'SELECT')"));
        $this->assertTrue(DB::scalar("SELECT has_table_privilege('simrs_analitik', 'sdm.pegawai', 'SELECT')"));
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

    public function test_redis_tersedia(): void
    {
        $this->assertSame('PONG', (string) Redis::connection()->ping());
    }
}
