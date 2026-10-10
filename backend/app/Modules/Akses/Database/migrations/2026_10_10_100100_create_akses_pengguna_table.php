<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * akses.pengguna (PRD 11.2). Kolom peserta_didik_id dan mfa_rahasia_enkripsi menyusul di
 * tiketnya (pendidikan klinis, MFA 1.1b). simrs_app tidak punya DELETE di skema akses:
 * akun dinonaktifkan lewat status, tidak dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('akses.pengguna', function (Blueprint $table) {
            $table->comment('Akun masuk SIMRS. Setiap akun milik satu pegawai.');
            $table->uuid('id')->primary();
            $table->foreignUuid('pegawai_id')->constrained('sdm.pegawai')->restrictOnDelete();
            $table->text('nama_pengguna')->comment('Unik tanpa membedakan huruf besar/kecil');
            $table->text('surel')->nullable()->comment('PRIBADI: kontak');
            $table->text('sandi_hash')->comment('RAHASIA: hash Argon2id; tidak pernah dikirim ke klien atau log');
            $table->text('status')->default('aktif')->comment('aktif | nonaktif; nonaktif mencabut sesi yang berjalan');
            $table->timestampTz('login_terakhir_pada')->nullable();
            $table->smallInteger('percobaan_gagal')->default(0)->comment('Gagal masuk beruntun sejak berhasil/terkunci terakhir');
            $table->timestampTz('terkunci_sampai')->nullable()->comment('Akun ditolak masuk sampai waktu ini');
            $table->kolomStandar();

            $table->index('pegawai_id');
        });

        DB::statement("ALTER TABLE akses.pengguna ADD CONSTRAINT pengguna_status_cek CHECK (status IN ('aktif', 'nonaktif'))");
        DB::statement('CREATE UNIQUE INDEX pengguna_nama_pengguna_unik ON akses.pengguna (lower(nama_pengguna))');
    }

    public function down(): void
    {
        Schema::dropIfExists('akses.pengguna');
    }
};
