<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class InfrastrukturTest extends TestCase
{
    /** Tabel contoh dibuat oleh simrs_migrator agar hak bawaan per skema ikut teruji. */
    private const TABEL_CONTOH = ['inti.uji_hak_akses', 'audit.uji_hak_akses'];

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

    public function test_redis_tersedia(): void
    {
        $this->assertSame('PONG', (string) Redis::connection()->ping());
    }

    /**
     * Pastikan perintah ditolak karena kurang hak (SQLSTATE 42501). Dibungkus savepoint
     * agar transaksi tes tidak ikut batal dan perintah berikutnya tetap bisa jalan.
     */
    private function assertDitolak(callable $perintah): void
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
