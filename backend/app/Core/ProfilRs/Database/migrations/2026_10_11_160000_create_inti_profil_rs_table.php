<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profil RS dan paket identitas (PRD 3.4, 11.2 inti.profil_rs, FR-54-08 langkah 2; tiket 1.8a).
 * Satu instalasi = satu RS: paling banyak satu baris. Instalasi kosong belum punya baris; GET /profil-rs
 * memakai nilai bawaan produk sampai wizard mengisinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inti.profil_rs', function (Blueprint $table) {
            $table->comment('Profil dan paket identitas RS (satu baris per instalasi, PRD 3.4).');
            $table->uuid('id')->primary();
            $table->text('nama')->nullable()->comment('Kosong sampai langkah 2 wizard diisi (berkas boleh diunggah lebih dulu)');
            $table->text('kode_kemenkes')->nullable();
            $table->text('kelas')->nullable()->comment('Klasifikasi sesuai izin operasional; tanpa daftar tetap (PRD 17.2 T-1)');
            $table->boolean('status_blu')->nullable();
            $table->text('kode_ppk_bpjs')->nullable();
            $table->text('alamat')->nullable();
            $table->jsonb('paket_identitas')->comment('{warna: {brand, accent}, logo|motif|foto: {path, tipe, ukuran, sha256, diunggah_pada}}');
            $table->text('kontak_admin_unit')->default('admin SIMRS')->comment('Unit pengelola akun, untuk teks halaman masuk dan Beranda');
            $table->text('kontak_admin')->nullable();
            $table->text('status_penyiapan')->default('sebagian')->comment('belum | sebagian | selesai (FR-54-08 langkah 2)');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE inti.profil_rs ADD CONSTRAINT profil_rs_status_penyiapan_cek CHECK (status_penyiapan IN ('belum', 'sebagian', 'selesai'))");
        // Satu instalasi = satu RS.
        DB::statement('CREATE UNIQUE INDEX profil_rs_tunggal ON inti.profil_rs ((true))');
    }

    public function down(): void
    {
        Schema::dropIfExists('inti.profil_rs');
    }
};
