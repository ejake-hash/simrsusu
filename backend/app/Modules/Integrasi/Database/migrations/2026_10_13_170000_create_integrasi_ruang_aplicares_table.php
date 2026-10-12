<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ruang yang pernah dikirim ke Aplicares per lingkungan (tiket 1.9 butir 5). Ruang yang tidak ada lagi di keadaan
 * terkini sebuah bangsal (kelas TT diubah, kamar pindah bangsal, kode bangsal/kelas Aplicares diubah) dikirim dengan
 * kapasitas 0 agar angka lama tidak tertinggal di BPJS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrasi.ruang_aplicares', function (Blueprint $table) {
            $table->comment('Ruang Aplicares (kodekelas × koderuang) yang pernah dikirim, dengan kapasitas terakhir.');
            $table->uuid('id')->primary();
            $table->text('lingkungan');
            $table->text('kodekelas');
            $table->text('koderuang');
            $table->uuid('bangsal_id')->comment('Bangsal pengirim terakhir (inti.bangsal)');
            $table->integer('kapasitas');
            $table->timestampTz('dikirim_pada');
            $table->kolomStandar();
            $table->unique(['lingkungan', 'kodekelas', 'koderuang']);
            $table->index(['lingkungan', 'bangsal_id']);
        });
        DB::statement("ALTER TABLE integrasi.ruang_aplicares ADD CONSTRAINT ruang_aplicares_lingkungan_cek CHECK (lingkungan IN ('sandbox', 'produksi'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('integrasi.ruang_aplicares');
    }
};
