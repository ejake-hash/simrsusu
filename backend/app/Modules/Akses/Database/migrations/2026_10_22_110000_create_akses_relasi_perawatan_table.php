<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Relasi perawatan (tiket 2.6; PRD 9.2.2 lapis 4): pegawai yang sedang merawat pasien dalam rentang waktu tertentu,
 * dibentuk modul pelayanan (mula-mula: dokter jadwal kunjungan poli). Tidak dihapus: diakhiri lewat status `batal`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('akses.relasi_perawatan', function (Blueprint $table) {
            $table->comment('Pegawai yang merawat pasien (lapis 4). Berlaku hanya di antara berlaku_dari dan berlaku_sampai selama status aktif.');
            $table->uuid('id')->primary();
            $table->foreignUuid('pasien_id')->constrained('pasien.pasien')->restrictOnDelete();
            $table->foreignUuid('pegawai_id')->constrained('sdm.pegawai')->restrictOnDelete();
            $table->text('peran')->comment('dokter_jadwal (tiket 2.6); peran lain (DPJP rawat inap, perawat) menyusul');
            $table->text('sumber_jenis')->comment('Objek yang melahirkan relasi, mis. kunjungan');
            $table->uuid('sumber_id');
            $table->timestampTz('berlaku_dari');
            $table->timestampTz('berlaku_sampai');
            $table->text('status')->default('aktif');
            $table->text('alasan_batal')->nullable();
            $table->uuid('dibatalkan_oleh')->nullable()->comment('akses.pengguna.id; null = sistem');
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->unique(['sumber_jenis', 'sumber_id', 'pegawai_id', 'peran']);
            $table->index(['pasien_id', 'pegawai_id', 'status', 'berlaku_sampai']);
        });
        DB::statement("ALTER TABLE akses.relasi_perawatan ADD CONSTRAINT relasi_perawatan_cek CHECK (peran IN ('dokter_jadwal') AND sumber_jenis IN ('kunjungan') AND status IN ('aktif', 'batal') AND berlaku_sampai > berlaku_dari AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('akses.relasi_perawatan');
    }
};
