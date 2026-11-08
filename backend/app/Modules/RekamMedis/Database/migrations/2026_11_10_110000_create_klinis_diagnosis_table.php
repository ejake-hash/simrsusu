<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnosis kunjungan (tiket 2.15, kontrak v0.25.0; PRD 11.2 `klinis.diagnosis`, FR-07-05). Kode wajib ada di paket
 * referensi yang dimuat RS (K7): kunci tamu ke inti.terminologi. Tanpa DELETE: salah catat = status batal + alasan.
 * Asumsi PR kontrak: `pasien_id` (ringkasan lintas kunjungan) dan status batal menggantikan kolom `aktif`; `mulai` menyusul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('klinis.diagnosis', function (Blueprint $table) {
            $table->comment('Diagnosis per kunjungan (PRD 11.2). Dokter: utama/sekunder; koder (komplikasi/komorbid) menyusul Tahap D.');
            $table->uuid('id')->primary();
            $table->foreignUuid('kunjungan_id')->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->foreignUuid('pasien_id')->constrained('pasien.pasien')->restrictOnDelete();
            $table->text('sistem_icd')->default('icd10');
            $table->text('kode_icd')->comment('KLINIS: tidak pernah ke log/audit');
            $table->text('jenis');
            $table->text('sumber')->default('dokter');
            $table->jsonb('bukti')->nullable()->comment('Tautan bukti RM untuk koder (FR-27-03), Tahap D');
            $table->text('status')->default('aktif');
            $table->text('alasan_batal')->nullable()->comment('KLINIS: tidak pernah ke log/audit');
            $table->uuid('dibatalkan_oleh')->nullable();
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->foreign(['sistem_icd', 'kode_icd'])->references(['sistem', 'kode'])->on('inti.terminologi')->restrictOnDelete();
            $table->index(['kunjungan_id', 'dibuat_pada', 'id']);
            $table->index(['pasien_id', 'dibuat_pada']);
        });
        DB::statement("ALTER TABLE klinis.diagnosis ADD CONSTRAINT diagnosis_cek CHECK (jenis IN ('utama', 'sekunder', 'komplikasi', 'komorbid') AND sumber IN ('dokter', 'koder') AND status IN ('aktif', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL AND alasan_batal IS NOT NULL)))");
        // 409 diagnosis_utama_sudah_ada dan diagnosis_sudah_tercatat dijaga juga oleh basis data (permintaan serentak).
        DB::statement("CREATE UNIQUE INDEX diagnosis_utama_unik ON klinis.diagnosis (kunjungan_id) WHERE jenis = 'utama' AND status = 'aktif'");
        DB::statement("CREATE UNIQUE INDEX diagnosis_kode_unik ON klinis.diagnosis (kunjungan_id, sistem_icd, kode_icd) WHERE status = 'aktif'");
    }

    public function down(): void
    {
        Schema::dropIfExists('klinis.diagnosis');
    }
};
