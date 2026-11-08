<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order penunjang bersama (tiket 2.16, kontrak v0.26.0; PRD 11.2 `permintaan`, 7.00.5). Terikat kunjungan dan episode saat
 * dipesan. Tanpa DELETE: pembatalan = status batal + alasan. Asumsi PR kontrak: `episode_id`, `pasien_id` (cek order ganda),
 * `alasan_ganda` (FR-07-27); `satusehat_id` menyusul Tahap D, `paket_order` menyusul tiketnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permintaan.permintaan_pemeriksaan', function (Blueprint $table) {
            $table->comment('Order penunjang dari pos mana pun (7.00.5). Indikasi dan alasan = data klinis: tidak ke log/audit.');
            $table->uuid('id')->primary();
            $table->foreignUuid('kunjungan_id')->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->foreignUuid('episode_id')->constrained('kunjungan.episode')->restrictOnDelete();
            $table->foreignUuid('pasien_id')->constrained('pasien.pasien')->restrictOnDelete();
            $table->text('jenis');
            $table->text('prioritas');
            $table->uuid('pemesan_id')->comment('akses.pengguna.id');
            $table->text('diagnosis_kerja')->comment('KLINIS: indikasi, tidak pernah ke log/audit');
            $table->text('catatan')->nullable()->comment('KLINIS: tidak pernah ke log/audit');
            $table->text('alasan_ganda')->nullable()->comment('KLINIS: tidak pernah ke log/audit');
            $table->text('status')->default('dipesan');
            $table->text('alasan_batal')->nullable()->comment('KLINIS: tidak pernah ke log/audit');
            $table->uuid('dibatalkan_oleh')->nullable();
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->foreign('pemesan_id')->references('id')->on('akses.pengguna')->restrictOnDelete();
            $table->index(['episode_id', 'dibuat_pada', 'id']);
            $table->index(['pasien_id', 'dibuat_pada']);
        });
        DB::statement("ALTER TABLE permintaan.permintaan_pemeriksaan ADD CONSTRAINT permintaan_pemeriksaan_cek CHECK (jenis IN ('lab', 'rad') AND prioritas IN ('rutin', 'cito') AND status IN ('dipesan', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL AND alasan_batal IS NOT NULL)))");

        Schema::create('permintaan.permintaan_item', function (Blueprint $table) {
            $table->comment('Butir order = butir buku tarif yang berlaku saat dipesan (2.2).');
            $table->uuid('id')->primary();
            $table->foreignUuid('permintaan_pemeriksaan_id')->constrained('permintaan.permintaan_pemeriksaan')->restrictOnDelete();
            $table->foreignUuid('tarif_id')->constrained('keuangan.tarif')->restrictOnDelete();
            $table->text('kode_item')->comment('Kode tarif saat dipesan');
            $table->text('nama')->comment('Nama versi tarif yang berlaku saat dipesan');
            $table->text('status')->default('dipesan');
            $table->kolomStandar();

            $table->unique(['permintaan_pemeriksaan_id', 'tarif_id']);
            $table->index('tarif_id');
        });
        DB::statement("ALTER TABLE permintaan.permintaan_item ADD CONSTRAINT permintaan_item_cek CHECK (status IN ('dipesan', 'batal'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('permintaan.permintaan_item');
        Schema::dropIfExists('permintaan.permintaan_pemeriksaan');
    }
};
