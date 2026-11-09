<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Triase IGD dan ulang triase (tiket 2.24; FR-03-02, PRD 11.2 `triase`). Instrumen dan skalanya data induk RS (aturan
 * Tahap C): migrasi hanya membuat strukturnya, tanpa isi. Ulang triase = baris baru; simrs_app tidak boleh mengubah
 * baris triase. Jam tiba tidak disalin: dibaca dari kunjungan.kunjungan.tiba_pada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inti.instrumen_triase', function (Blueprint $table) {
            $table->comment('Instrumen triase yang disetujui RS (mis. skala lima tingkat) beserta daftar skalanya. Diisi RS; tidak dihapus, nonaktif bila tidak dipakai.');
            $table->uuid('id')->primary();
            $table->text('kode')->unique();
            $table->text('nama');
            $table->jsonb('skala')->default('[]')->comment('[{kode, label}] urut tampil; [] = instrumen tanpa skala');
            $table->text('status')->default('aktif');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE inti.instrumen_triase ADD CONSTRAINT instrumen_triase_cek CHECK (status IN ('aktif', 'nonaktif') AND jsonb_typeof(skala) = 'array')");

        Schema::create('kunjungan.triase', function (Blueprint $table) {
            $table->comment('Triase kunjungan IGD (FR-03-02). Hanya ditambah: ulang triase = baris baru, triase berlaku = ditriase_pada terbaru.');
            $table->uuid('id')->primary();
            $table->foreignUuid('kunjungan_id')->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->text('kategori')->comment('merah | kuning | hijau | hitam');
            $table->foreignUuid('instrumen_triase_id')->nullable()->constrained('inti.instrumen_triase')->restrictOnDelete();
            $table->text('skala')->nullable()->comment('Kode skala dari instrumen_triase.skala saat ditriase');
            // Salinan saat triase: riwayat tidak berubah arti bila data induk instrumen diedit sesudahnya.
            $table->text('instrumen_kode')->nullable()->comment('Salinan kode instrumen saat ditriase');
            $table->text('instrumen_nama')->nullable()->comment('Salinan nama instrumen saat ditriase');
            $table->text('skala_label')->nullable()->comment('Salinan label skala saat ditriase');
            $table->text('keluhan')->nullable()->comment('KLINIS: keluhan saat triase; tidak pernah di log/audit');
            $table->timestampTz('ditriase_pada')->comment('Jam server');
            $table->foreignUuid('petugas_id')->comment('akses.pengguna.id')->constrained('akses.pengguna')->restrictOnDelete();
            $table->timestampTz('dibuat_pada')->useCurrent();

            $table->index(['kunjungan_id', 'ditriase_pada', 'id']);
        });
        DB::statement("ALTER TABLE kunjungan.triase ADD CONSTRAINT triase_cek CHECK (kategori IN ('merah', 'kuning', 'hijau', 'hitam') AND (skala IS NULL OR instrumen_triase_id IS NOT NULL) AND ((instrumen_triase_id IS NULL) = (instrumen_nama IS NULL) AND (instrumen_triase_id IS NULL) = (instrumen_kode IS NULL)) AND ((skala IS NULL) = (skala_label IS NULL)))");
        // Hanya ditambah (pola kunjungan.antrean_jejak): riwayat triase tidak bisa diubah simrs_app.
        DB::statement('REVOKE UPDATE ON kunjungan.triase FROM simrs_app');
    }

    public function down(): void
    {
        Schema::dropIfExists('kunjungan.triase');
        Schema::dropIfExists('inti.instrumen_triase');
    }
};
