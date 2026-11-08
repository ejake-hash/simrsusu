<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Tiket 2.15: pencarian kode terminologi (GET /paket-referensi/{sistem}/kode) memakai pg_trgm pada kode + tampilan. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX terminologi_cari_trgm ON inti.terminologi USING gin ((kode || ' ' || tampilan) gin_trgm_ops)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inti.terminologi_cari_trgm');
    }
};
