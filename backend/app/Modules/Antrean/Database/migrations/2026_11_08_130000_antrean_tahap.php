<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Tiket 2.13: antrean poli satu nomor untuk perawat lalu dokter; `tahap` menandai siapa yang ditunggu (FR-02-09). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE kunjungan.antrean ADD COLUMN tahap text NOT NULL DEFAULT 'perawat' CHECK (tahap IN ('perawat', 'dokter'))");
        DB::statement("COMMENT ON COLUMN kunjungan.antrean.tahap IS 'Titik poli: perawat (asesmen awal belum selesai) atau dokter'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE kunjungan.antrean DROP COLUMN tahap');
    }
};
