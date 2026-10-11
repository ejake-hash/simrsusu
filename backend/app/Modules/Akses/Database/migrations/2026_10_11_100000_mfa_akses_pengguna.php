<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFA TOTP + kode cadangan (tiket 1.1b, PRD 10.3.2). Kode cadangan tidak dihapus (simrs_app tidak
 * punya DELETE di skema akses): yang terpakai diberi dipakai_pada, yang diganti diberi dibatalkan_pada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('akses.pengguna', function (Blueprint $table) {
            $table->text('mfa_rahasia_enkripsi')->nullable()->comment('RAHASIA: rahasia TOTP, terenkripsi aplikasi (APP_KEY)');
            $table->timestampTz('mfa_aktif_pada')->nullable()->comment('Saat MFA selesai didaftarkan; kosong = belum punya MFA');
            $table->bigInteger('mfa_langkah_terakhir')->nullable()->comment('Langkah waktu TOTP terakhir yang diterima; kode di langkah ini atau sebelumnya ditolak (anti pakai ulang)');
            $table->boolean('wajib_mfa')->default(false)->comment('Wajib MFA walau dari jaringan RS; sebelum tiket 1.2 diisi admin, sesudahnya diturunkan dari peran');
        });

        Schema::create('akses.kode_cadangan_mfa', function (Blueprint $table) {
            $table->comment('Kode cadangan MFA, sekali pakai, disimpan sebagai hash Argon2id.');
            $table->uuid('id')->primary();
            $table->foreignUuid('pengguna_id')->constrained('akses.pengguna')->restrictOnDelete();
            $table->text('kode_hash')->comment('RAHASIA: hash Argon2id kode cadangan');
            $table->timestampTz('dipakai_pada')->nullable();
            $table->timestampTz('dibatalkan_pada')->nullable()->comment('Diisi saat kode baru dibuat atau MFA direset');
            $table->kolomStandar();

            $table->index('pengguna_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('akses.kode_cadangan_mfa');
        Schema::table('akses.pengguna', function (Blueprint $table) {
            $table->dropColumn(['mfa_rahasia_enkripsi', 'mfa_aktif_pada', 'mfa_langkah_terakhir', 'wajib_mfa']);
        });
    }
};
