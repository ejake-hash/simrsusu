<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Istilah umum (tiket 1.0g, PRD 10.3.3): kolom surel → email. RENAME, data tidak berpindah. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('akses.pengguna', 'surel')) {
            DB::statement('ALTER TABLE akses.pengguna RENAME COLUMN surel TO email');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('akses.pengguna', 'email')) {
            DB::statement('ALTER TABLE akses.pengguna RENAME COLUMN email TO surel');
        }
    }
};
