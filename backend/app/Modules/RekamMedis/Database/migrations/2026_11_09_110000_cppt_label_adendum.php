<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CPPT episode (tiket 2.14, kontrak v0.24.0): label sensitivitas catatan klinis (lapis 4; kolom di luar PRD 11.2, asumsi
 * PR kontrak) dan adendum bertanda tangan (FR-26-06). Tanpa DELETE; adendum tidak pernah diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('klinis.catatan_klinis', function (Blueprint $table) {
            $table->text('label')->nullable()->comment('Kode akses.label_sensitivitas; null = tidak berlabel (lapis 4, 9.2.5)');
        });
        // Linimasa lintas episode pasien: keyset (dibuat_pada, id).
        DB::statement('CREATE INDEX catatan_klinis_pasien_linimasa_idx ON klinis.catatan_klinis (pasien_id, dibuat_pada, id)');
        DB::statement('DROP INDEX IF EXISTS klinis.klinis_catatan_klinis_pasien_id_dibuat_pada_index');

        Schema::create('klinis.catatan_klinis_adendum', function (Blueprint $table) {
            $table->comment('Adendum catatan klinis sesudah batas perbaikan (FR-26-06), selalu bertanda tangan T1. Hanya ditambah.');
            $table->uuid('id')->primary();
            $table->foreignUuid('catatan_klinis_id')->constrained('klinis.catatan_klinis')->restrictOnDelete();
            $table->foreignUuid('penulis_id')->constrained('akses.pengguna')->restrictOnDelete();
            $table->text('alasan')->comment('KLINIS: tidak pernah ke log/audit');
            $table->text('isi')->comment('KLINIS: tidak pernah ke log/audit');
            $table->timestampTz('disahkan_pada');
            $table->foreignUuid('tanda_tangan_id')->nullable()->constrained('ttd.tanda_tangan')->restrictOnDelete();

            $table->index(['catatan_klinis_id', 'disahkan_pada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('klinis.catatan_klinis_adendum');
        DB::statement('DROP INDEX IF EXISTS klinis.catatan_klinis_pasien_linimasa_idx');
        DB::statement('CREATE INDEX klinis_catatan_klinis_pasien_id_dibuat_pada_index ON klinis.catatan_klinis (pasien_id, dibuat_pada)');
        Schema::table('klinis.catatan_klinis', fn (Blueprint $table) => $table->dropColumn('label'));
    }
};
