<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD 11.2 menandai tabel ini untuk dipartisi per bulan dan diberi hash_rantai.
 * Keduanya belum di sini: dibuat sebagai tabel biasa dulu, diubah sebelum data produksi ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit.log_aktivitas', function (Blueprint $table) {
            $table->comment('Jejak aktivitas pengguna. simrs_app hanya SELECT, INSERT.');
            $table->uuid('id')->primary();
            $table->uuid('pengguna_id')->nullable()->comment('akses.pengguna.id; kosong bila pelaku tidak dikenal (mis. gagal masuk akun tak ada)');
            $table->text('aksi')->comment('Kode aksi, mis. autentikasi.masuk');
            $table->text('subjek_jenis')->nullable()->comment('Jenis objek yang dikenai aksi, mis. akses.pengguna');
            $table->uuid('subjek_id')->nullable()->comment('id objek yang dikenai aksi');
            $table->jsonb('sebelum')->nullable()->comment('Keadaan sebelum; tanpa sandi, token, atau data pasien');
            $table->jsonb('sesudah')->nullable()->comment('Keadaan sesudah atau rincian; tanpa sandi, token, atau data pasien');
            $table->ipAddress('ip')->nullable()->comment('PRIBADI: alamat IP');
            $table->text('perangkat')->nullable()->comment('User-Agent, dipotong 255 karakter');
            $table->timestampTz('terjadi_pada', 6)->useCurrent();

            $table->index(['pengguna_id', 'terjadi_pada']);
            $table->index(['aksi', 'terjadi_pada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit.log_aktivitas');
    }
};
