<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unit organisasi dan KSM (PRD 11.2, FR-54-08 langkah 3; tiket 1.8a). Tidak ada DELETE: dinonaktifkan lewat
 * status. Kepala unit dan ketua KSM (kolom ke sdm.pegawai) menyusul tiket 1.8d.
 * Juga: kunci tamu inti.modul_aktif.unit_organisasi_id (utang 1.2a) dan menu master_data.organisasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inti.unit_organisasi', function (Blueprint $table) {
            $table->comment('Struktur organisasi RS: instalasi, bidang, bagian, ruang, poli, depo (PRD 11.2).');
            $table->uuid('id')->primary();
            $table->uuid('induk_id')->nullable();
            $table->text('kode')->unique()->comment('Unik di seluruh RS; dipakai cakupan penugasan dan tampilan');
            $table->text('nama');
            $table->text('jenis')->comment('instalasi | bidang | bagian | ruang | poli | depo');
            $table->text('status')->default('aktif')->comment('aktif | nonaktif');
            $table->date('berlaku_dari');
            $table->kolomStandar();

            $table->index(['induk_id', 'kode']);
        });
        Schema::table('inti.unit_organisasi', fn (Blueprint $table) => $table->foreign('induk_id')->references('id')->on('inti.unit_organisasi')->restrictOnDelete());
        DB::statement("ALTER TABLE inti.unit_organisasi ADD CONSTRAINT unit_organisasi_jenis_cek CHECK (jenis IN ('instalasi', 'bidang', 'bagian', 'ruang', 'poli', 'depo'))");
        DB::statement("ALTER TABLE inti.unit_organisasi ADD CONSTRAINT unit_organisasi_status_cek CHECK (status IN ('aktif', 'nonaktif'))");
        DB::statement('ALTER TABLE inti.unit_organisasi ADD CONSTRAINT unit_organisasi_bukan_induk_sendiri CHECK (induk_id IS NULL OR induk_id <> id)');

        Schema::create('inti.ksm', function (Blueprint $table) {
            $table->comment('Kelompok Staf Medis (PRD 11.2).');
            $table->uuid('id')->primary();
            $table->text('kode')->unique();
            $table->text('nama');
            $table->text('status')->default('aktif')->comment('aktif | nonaktif');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE inti.ksm ADD CONSTRAINT ksm_status_cek CHECK (status IN ('aktif', 'nonaktif'))");

        Schema::table('inti.modul_aktif', fn (Blueprint $table) => $table->foreign('unit_organisasi_id')->references('id')->on('inti.unit_organisasi')->restrictOnDelete());

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        Schema::table('inti.modul_aktif', fn (Blueprint $table) => $table->dropForeign(['unit_organisasi_id']));
        Schema::dropIfExists('inti.ksm');
        Schema::dropIfExists('inti.unit_organisasi');
    }
};
