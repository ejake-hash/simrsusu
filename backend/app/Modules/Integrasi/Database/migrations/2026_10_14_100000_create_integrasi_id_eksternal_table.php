<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Id data lokal di sistem luar (PRD 11.2 `integrasi.id_eksternal`; tiket 1.9 butir 4): Location, Organization, IHS
 * Practitioner. Per lingkungan, karena id sandbox dan produksi berbeda. `sidik_sumber` = ringkasan data lokal yang
 * terakhir dikirim; data yang sidiknya kini berbeda dikirim ulang (tidak bergantung pada presisi atau urutan waktu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrasi.id_eksternal', function (Blueprint $table) {
            $table->comment('Id data lokal (lokasi, unit organisasi, pegawai) di sistem luar, per lingkungan.');
            $table->uuid('id')->primary();
            $table->text('sistem');
            $table->text('lingkungan');
            $table->text('jenis_lokal')->comment('lokasi | unit_organisasi | pegawai');
            $table->uuid('id_lokal');
            $table->text('id_luar');
            $table->text('sidik_sumber')->comment('Ringkasan data lokal yang terakhir dikirim/dicocokkan');
            $table->timestampTz('disinkron_pada')->comment('Terakhir dikirim/dicocokkan');
            $table->kolomStandar();
            $table->unique(['sistem', 'lingkungan', 'jenis_lokal', 'id_lokal']);
        });
        DB::statement("ALTER TABLE integrasi.id_eksternal ADD CONSTRAINT id_eksternal_sistem_cek CHECK (sistem IN ('bpjs_vclaim', 'aplicares', 'satusehat'))");
        DB::statement("ALTER TABLE integrasi.id_eksternal ADD CONSTRAINT id_eksternal_lingkungan_cek CHECK (lingkungan IN ('sandbox', 'produksi'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('integrasi.id_eksternal');
    }
};
