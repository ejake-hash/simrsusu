<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lokasi fisik, bangsal, kamar, tempat tidur, kelas rawat, poliklinik, dan kapasitas TT harian (tiket 1.8b;
 * PRD v0.10.11 FR-54-11…14, FR-08-16…20, 11.2). Tidak ada DELETE: lokasi ditutup lewat status, TT lewat
 * berlaku_sampai, data induk lain lewat status nonaktif. Kode TT unik selamanya (tidak dipakai ulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inti.kelas_rawat', function (Blueprint $table) {
            $table->comment('Kelas rawat (kelas lama dan KRIS berdampingan dengan tanggal berlaku; PRD T-4). Diisi RS.');
            $table->uuid('id')->primary();
            $table->text('kode')->unique();
            $table->text('nama');
            $table->text('kode_aplicares')->nullable()->comment('Diisi RS dari dokumen resmi BPJS');
            $table->date('berlaku_dari');
            $table->text('status')->default('aktif');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE inti.kelas_rawat ADD CONSTRAINT kelas_rawat_status_cek CHECK (status IN ('aktif', 'nonaktif'))");

        Schema::create('inti.lokasi', function (Blueprint $table) {
            $table->comment('Lokasi fisik bertingkat (FR-54-11): situs → gedung → sayap → lantai → ruang → tempat_tidur. Terpisah dari unit organisasi.');
            $table->uuid('id')->primary();
            $table->uuid('induk_id')->nullable();
            $table->text('jenis')->comment('situs | gedung | sayap | lantai | ruang | tempat_tidur');
            $table->text('kode')->comment('Segmen, unik di antara saudara (lewat kode_lengkap)');
            $table->text('kode_lengkap')->unique()->comment('Gabungan kode dari akar dengan "-", mis. B-3-304-2');
            $table->text('nama');
            $table->text('fungsi')->nullable()->comment('Untuk ruang: rawat_inap | rawat_jalan | igd | kamar_operasi | intensif | penunjang | administrasi | gudang | lainnya');
            $table->foreignUuid('unit_organisasi_id')->nullable()->comment('Pengelola saat ini')->constrained('inti.unit_organisasi')->restrictOnDelete();
            $table->text('status')->default('aktif')->comment('aktif | renovasi | ditutup');
            $table->date('status_berlaku_dari');
            $table->text('satusehat_lokasi_id')->nullable()->comment('Diisi integrasi SATUSEHAT (tiket 1.9)');
            $table->kolomStandar();

            $table->index('induk_id');
        });
        Schema::table('inti.lokasi', fn (Blueprint $table) => $table->foreign('induk_id')->references('id')->on('inti.lokasi')->restrictOnDelete());
        DB::statement("ALTER TABLE inti.lokasi ADD CONSTRAINT lokasi_jenis_cek CHECK (jenis IN ('situs', 'gedung', 'sayap', 'lantai', 'ruang', 'tempat_tidur'))");
        DB::statement("ALTER TABLE inti.lokasi ADD CONSTRAINT lokasi_status_cek CHECK (status IN ('aktif', 'renovasi', 'ditutup'))");
        DB::statement("ALTER TABLE inti.lokasi ADD CONSTRAINT lokasi_fungsi_cek CHECK ((jenis = 'ruang') = (fungsi IS NOT NULL) AND (fungsi IS NULL OR fungsi IN ('rawat_inap', 'rawat_jalan', 'igd', 'kamar_operasi', 'intensif', 'penunjang', 'administrasi', 'gudang', 'lainnya')))");
        DB::statement('ALTER TABLE inti.lokasi ADD CONSTRAINT lokasi_bukan_induk_sendiri CHECK (induk_id IS NULL OR induk_id <> id)');
        DB::statement("ALTER TABLE inti.lokasi ADD CONSTRAINT lokasi_kode_cek CHECK (kode ~ '^[A-Z0-9]{1,10}$')");

        Schema::create('inti.bangsal', function (Blueprint $table) {
            $table->comment('Bangsal rawat inap: kelompok kamar yang dikelola satu unit (PRD 11.2).');
            $table->uuid('id')->primary();
            $table->text('kode')->unique();
            $table->text('nama');
            $table->foreignUuid('unit_organisasi_id')->constrained('inti.unit_organisasi')->restrictOnDelete();
            $table->text('status')->default('aktif');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE inti.bangsal ADD CONSTRAINT bangsal_status_cek CHECK (status IN ('aktif', 'nonaktif'))");

        Schema::create('inti.kamar', function (Blueprint $table) {
            $table->comment('Ruang (lokasi jenis ruang) yang menjadi kamar rawat sebuah bangsal; satu ruang paling banyak satu kamar.');
            $table->uuid('id')->primary();
            $table->foreignUuid('bangsal_id')->constrained('inti.bangsal')->restrictOnDelete();
            $table->foreignUuid('lokasi_id')->unique()->constrained('inti.lokasi')->restrictOnDelete();
            $table->smallInteger('kapasitas_maksimum')->nullable();
            $table->jsonb('atribut_standar')->default('{}')->comment('Daftar periksa standar ruang rawat (FR-08-19), kunci-nilai');
            $table->kolomStandar();

            $table->index('bangsal_id');
        });
        DB::statement('ALTER TABLE inti.kamar ADD CONSTRAINT kamar_kapasitas_cek CHECK (kapasitas_maksimum IS NULL OR kapasitas_maksimum BETWEEN 1 AND 99)');

        Schema::create('inti.tempat_tidur', function (Blueprint $table) {
            $table->comment('Tempat tidur (FR-08-16…18). Kode tidak pernah dihapus atau dipakai ulang; ditutup lewat berlaku_sampai.');
            $table->uuid('id')->primary();
            $table->foreignUuid('kamar_id')->constrained('inti.kamar')->restrictOnDelete();
            $table->foreignUuid('lokasi_id')->unique()->constrained('inti.lokasi')->restrictOnDelete();
            $table->text('kode')->unique()->comment('Tetap sejak dibuat, unik selamanya');
            $table->foreignUuid('kelas_rawat_id')->constrained('inti.kelas_rawat')->restrictOnDelete();
            $table->text('aturan_jenis_kelamin')->default('campur');
            $table->boolean('isolasi')->default(false);
            $table->boolean('ekstra')->default(false)->comment('TT tambahan saat lonjakan, dihitung terpisah');
            $table->date('berlaku_dari')->comment('Hari pertama dihitung dalam kapasitas');
            $table->date('berlaku_sampai')->nullable()->comment('Hari terakhir dihitung; null = tanpa batas');
            $table->text('alasan_tutup')->nullable();
            $table->text('satusehat_lokasi_id')->nullable();
            $table->kolomStandar();

            $table->index('kamar_id');
            $table->index(['kelas_rawat_id', 'berlaku_sampai']);
        });
        DB::statement("ALTER TABLE inti.tempat_tidur ADD CONSTRAINT tempat_tidur_jenis_kelamin_cek CHECK (aturan_jenis_kelamin IN ('campur', 'laki_laki', 'perempuan'))");
        DB::statement('ALTER TABLE inti.tempat_tidur ADD CONSTRAINT tempat_tidur_masa_cek CHECK (berlaku_sampai IS NULL OR berlaku_sampai >= berlaku_dari - 1)');

        Schema::create('inti.kapasitas_tempat_tidur_harian', function (Blueprint $table) {
            $table->comment('Rekaman kapasitas TT per tanggal, bangsal, dan kelas; penyebut BOR/BTO/TOI (FR-08-18). Tidak diubah setelah harinya lewat.');
            $table->uuid('id')->primary();
            $table->date('tanggal');
            $table->foreignUuid('bangsal_id')->constrained('inti.bangsal')->restrictOnDelete();
            $table->foreignUuid('kelas_rawat_id')->constrained('inti.kelas_rawat')->restrictOnDelete();
            $table->integer('jumlah_tersedia');
            $table->integer('jumlah_ekstra');
            $table->kolomStandar();

            $table->unique(['tanggal', 'bangsal_id', 'kelas_rawat_id']);
        });

        Schema::create('inti.poliklinik', function (Blueprint $table) {
            $table->comment('Poliklinik rawat jalan (PRD 11.2).');
            $table->uuid('id')->primary();
            $table->text('kode')->unique();
            $table->text('nama');
            $table->foreignUuid('unit_organisasi_id')->constrained('inti.unit_organisasi')->restrictOnDelete();
            $table->foreignUuid('lokasi_id')->nullable()->comment('Ruang poli (penunjuk arah)')->constrained('inti.lokasi')->restrictOnDelete();
            $table->text('kode_poli_bpjs')->nullable()->comment('Diisi RS dari referensi BPJS resmi');
            $table->text('status')->default('aktif');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE inti.poliklinik ADD CONSTRAINT poliklinik_status_cek CHECK (status IN ('aktif', 'nonaktif'))");

        // Label sub-menu master_data.organisasi disamakan dengan PRD 9.1 ("Organisasi, Unit, Ruang & Tempat Tidur").
        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        Schema::dropIfExists('inti.poliklinik');
        Schema::dropIfExists('inti.kapasitas_tempat_tidur_harian');
        Schema::dropIfExists('inti.tempat_tidur');
        Schema::dropIfExists('inti.kamar');
        Schema::dropIfExists('inti.bangsal');
        Schema::dropIfExists('inti.lokasi');
        Schema::dropIfExists('inti.kelas_rawat');
    }
};
