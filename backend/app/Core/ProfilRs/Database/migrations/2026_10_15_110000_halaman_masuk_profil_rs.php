<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Teks panel halaman masuk yang diatur admin (tiket 1.15). Null = teks bawaan produk (ProfilRs::HALAMAN_MASUK_BAWAAN). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE inti.profil_rs ADD COLUMN halaman_masuk jsonb');
        DB::statement("COMMENT ON COLUMN inti.profil_rs.halaman_masuk IS 'Panel halaman masuk (lencana, judul, judul_sorot, uraian, fitur[3], foto_keterangan, tampilkan_motif); null = bawaan produk'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inti.profil_rs DROP COLUMN halaman_masuk');
    }
};
