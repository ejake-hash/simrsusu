<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paket data referensi (tiket 1.8e; FR-54-09, PRD 11.2 inti.paket_referensi/wilayah/referensi/terminologi; K7). Kode
 * tidak pernah dihapus: versi baru menonaktifkan kode yang hilang (tetap terbaca di data historis).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inti.paket_referensi', function (Blueprint $table) {
            $table->comment('Riwayat pemuatan paket referensi: sistem, versi, sumber, lisensi (FR-54-09).');
            $table->uuid('id')->primary();
            $table->text('sistem')->comment('wilayah | referensi | icd10 | icd9cm | loinc | ucum');
            $table->text('versi');
            $table->text('sumber');
            $table->text('catatan_lisensi')->nullable();
            $table->integer('jumlah_kode');
            $table->char('sidik_isi', 64)->comment('SHA-256 isi ternormalisasi: versi sama dengan isi lain ditolak (409 versi_sudah_dimuat)');
            $table->timestampTz('dimuat_pada');
            $table->uuid('dimuat_oleh')->nullable()->comment('Kosong = konsol');

            $table->unique(['sistem', 'versi']);
        });

        $kolomStatus = function (Blueprint $table) {
            $table->boolean('aktif')->default(true);
            $table->text('versi_terakhir')->comment('Versi paket terakhir yang memuat kode ini');
            $table->timestampTz('dinonaktifkan_pada')->nullable();
            $table->timestampTz('dibuat_pada')->useCurrent();
            $table->timestampTz('diubah_pada')->useCurrent();
        };

        Schema::create('inti.wilayah', function (Blueprint $table) use ($kolomStatus) {
            $table->comment('Wilayah administrasi Kemendagri (provinsi s.d. kelurahan).');
            $table->uuid('id')->primary();
            $table->text('kode_kemendagri')->unique();
            $table->text('tingkat');
            $table->text('nama');
            $table->uuid('induk_id')->nullable();
            $kolomStatus($table);
            $table->index(['induk_id', 'nama']);
        });
        DB::statement('ALTER TABLE inti.wilayah ADD CONSTRAINT wilayah_induk_fk FOREIGN KEY (induk_id) REFERENCES inti.wilayah (id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE inti.wilayah ADD CONSTRAINT wilayah_tingkat_cek CHECK (tingkat IN ('provinsi', 'kabupaten', 'kecamatan', 'kelurahan'))");

        Schema::create('inti.referensi', function (Blueprint $table) use ($kolomStatus) {
            $table->comment('Kode variabel RME/SATUSEHAT per kelompok (agama, pendidikan, pekerjaan, ...).');
            $table->uuid('id')->primary();
            $table->text('kelompok');
            $table->text('kode');
            $table->text('label');
            $kolomStatus($table);
            $table->unique(['kelompok', 'kode']);
        });

        Schema::create('inti.terminologi', function (Blueprint $table) use ($kolomStatus) {
            $table->comment('Terminologi klinis per sistem (ICD-10, ICD-9-CM dari berkas RS; LOINC subset, UCUM dikemas).');
            $table->uuid('id')->primary();
            $table->text('sistem');
            $table->text('kode');
            $table->text('tampilan');
            $table->text('kode_induk')->nullable();
            $kolomStatus($table);
            $table->unique(['sistem', 'kode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inti.terminologi');
        Schema::dropIfExists('inti.referensi');
        Schema::dropIfExists('inti.wilayah');
        Schema::dropIfExists('inti.paket_referensi');
    }
};
