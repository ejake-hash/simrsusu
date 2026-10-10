<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class InfrastrukturTest extends TestCase
{
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

    public function test_simrs_app_boleh_dml_tetapi_tidak_boleh_ddl(): void
    {
        DB::table('cache')->insert(['key' => 'uji', 'value' => '1', 'expiration' => 0]);
        DB::table('cache')->where('key', 'uji')->delete();

        $this->expectException(QueryException::class);
        DB::statement('CREATE TABLE sistem.uji_ddl (id int)');
    }

    public function test_redis_tersedia(): void
    {
        $this->assertSame('PONG', (string) Redis::connection()->ping());
    }
}
