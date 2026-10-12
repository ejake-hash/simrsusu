<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ruang yang pernah dikirim ke Aplicares per lingkungan (tiket 1.9 butir 5). Ruang yang tidak ada lagi di keadaan
 * terkini sebuah bangsal (kelas TT diubah, kamar pindah bangsal, kode bangsal/kelas Aplicares diubah) dikirim dengan
 * kapasitas 0 agar angka lama tidak tertinggal di BPJS. `sewa_agregat`: sewa atomik per agregat agar dua pekerja tidak
 * mengirim agregat yang sama bersamaan (aman untuk PgBouncer mode transaksi, PRD 14.1.1; kunci advisory sesi tidak).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrasi.ruang_aplicares', function (Blueprint $table) {
            $table->comment('Ruang Aplicares (kode kelas × kode ruang) yang pernah dikirim, dengan kapasitas terakhir.');
            $table->uuid('id')->primary();
            $table->text('lingkungan');
            $table->text('kode_kelas')->comment('Isian Aplicares kodekelas');
            $table->text('kode_ruang')->comment('Isian Aplicares koderuang');
            $table->uuid('bangsal_id')->comment('Bangsal pengirim terakhir (inti.bangsal)');
            $table->integer('kapasitas');
            $table->timestampTz('dikirim_pada');
            $table->kolomStandar();
            $table->unique(['lingkungan', 'kode_kelas', 'kode_ruang']);
            $table->index(['lingkungan', 'bangsal_id']);
        });
        DB::statement("ALTER TABLE integrasi.ruang_aplicares ADD CONSTRAINT ruang_aplicares_lingkungan_cek CHECK (lingkungan IN ('sandbox', 'produksi'))");

        Schema::create('integrasi.sewa_agregat', function (Blueprint $table) {
            $table->comment('Sewa pengiriman per agregat (mis. aplicares:bangsal:<id>); kedaluwarsa sendiri bila pekerja mati.');
            $table->text('kunci')->primary();
            $table->uuid('pemegang');
            $table->timestampTz('disewa_sampai');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrasi.sewa_agregat');
        Schema::dropIfExists('integrasi.ruang_aplicares');
    }
};
