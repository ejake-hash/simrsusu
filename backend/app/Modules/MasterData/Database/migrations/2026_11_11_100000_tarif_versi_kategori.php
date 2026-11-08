<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tiket 2.16 (kontrak v0.26.0): kategori butir tarif untuk katalog order penunjang (PRD 11.2 `tarif_versi` "Menyusul: kategori").
 * Null = bukan butir penunjang. Versi baru menyalin kategori versi sebelumnya (KelolaTarif).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE keuangan.tarif_versi ADD COLUMN kategori text NULL CHECK (kategori IN ('lab', 'rad'))");
        DB::statement("COMMENT ON COLUMN keuangan.tarif_versi.kategori IS 'Jenis order penunjang yang boleh memesan butir ini (lab/rad); null = bukan butir penunjang'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE keuangan.tarif_versi DROP COLUMN kategori');
    }
};
