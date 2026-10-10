<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versi minimal sdm.pegawai (PRD 11.2) untuk autentikasi. Kolom lain (nik_enkripsi, profesi,
 * unit_organisasi_id, jabatan, tmt, ihs_practitioner_id, ...) ditambah di tiket modul 39.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sdm.pegawai', function (Blueprint $table) {
            $table->comment('Pegawai RS. Satu pegawai bisa punya akun di akses.pengguna.');
            $table->uuid('id')->primary();
            $table->text('nip')->nullable()->unique()->comment('PRIBADI: nomor induk pegawai');
            $table->text('nama')->comment('PRIBADI: identitas_pegawai');
            $table->text('status')->default('aktif')->comment('aktif | nonaktif');
            $table->kolomStandar();
        });

        DB::statement("ALTER TABLE sdm.pegawai ADD CONSTRAINT pegawai_status_cek CHECK (status IN ('aktif', 'nonaktif'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sdm.pegawai');
    }
};
