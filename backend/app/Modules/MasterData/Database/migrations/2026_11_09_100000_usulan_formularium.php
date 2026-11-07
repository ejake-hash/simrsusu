<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Mesin usulan 1.8c menerima jenis data `formularium` (tiket 2.12, FR-54-02): CHECK + baris inti.alur_usulan (pola 2.2). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE inti.usulan_data_induk DROP CONSTRAINT usulan_jenis_cek');
        DB::statement("ALTER TABLE inti.usulan_data_induk ADD CONSTRAINT usulan_jenis_cek CHECK (jenis_data IN ('ruang_tt', 'tarif', 'formularium'))");
        DB::table('inti.alur_usulan')->insertOrIgnore(['id' => (string) Str::uuid7(), 'jenis_data' => 'formularium']);
    }

    public function down(): void
    {
        DB::table('inti.alur_usulan')->where('jenis_data', 'formularium')->delete();
        DB::statement('ALTER TABLE inti.usulan_data_induk DROP CONSTRAINT usulan_jenis_cek');
        DB::statement("ALTER TABLE inti.usulan_data_induk ADD CONSTRAINT usulan_jenis_cek CHECK (jenis_data IN ('ruang_tt', 'tarif'))");
    }
};
