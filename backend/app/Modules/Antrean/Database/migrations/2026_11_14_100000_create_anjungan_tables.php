<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anjungan pendaftaran mandiri tahap 1 (tiket 2.20; PRD 7.01.1, 11.2, FR-01-23/26/28, FR-02-01/07): perangkat anjungan,
 * sesi layanannya (tanpa identitas pasien), dan antrean titik `loket` (nomor `A-`/`P-`). Tanpa DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kunjungan.antrean', function (Blueprint $table) {
            $table->text('alasan_prioritas')->nullable()->comment('Titik loket: kategori prioritas pilihan pasien di anjungan (FR-02-07). Data kesehatan: tidak masuk audit/log');
            $table->index(['titik', 'tanggal', 'urutan', 'id']);
        });
        DB::statement("ALTER TABLE kunjungan.antrean ADD CONSTRAINT antrean_alasan_prioritas_cek CHECK (alasan_prioritas IS NULL OR (prioritas AND alasan_prioritas IN ('lansia', 'disabilitas', 'ibu_hamil', 'anak_khusus')))");

        Schema::create('kunjungan.anjungan', function (Blueprint $table) {
            $table->comment('Perangkat anjungan (PRD 11.2, 7.01.1, FR-01-23): perangkat, bukan akun orang. Token perangkat di sistem.personal_access_tokens (hash), pola display_antrean.');
            $table->uuid('id')->primary();
            $table->text('kode')->unique();
            $table->foreignUuid('lokasi_id')->comment('Lokasi perangkat (penunjuk arah)')->constrained('inti.lokasi')->restrictOnDelete();
            $table->text('mode')->default('pendaftaran');
            $table->text('area_loket')->default('loket')->comment('Area display nomor loket yang diambil di perangkat ini');
            $table->foreignUuid('lokasi_loket_id')->nullable()->comment('Loket admisi tujuan di struk; null = lokasi perangkat')->constrained('inti.lokasi')->restrictOnDelete();
            $table->integer('batas_diam_detik')->default(60)->comment('Sesi layar berakhir tanpa sentuhan (7.01.1, nilai awal 60)');
            $table->text('status')->default('aktif');
            $table->timestampTz('terakhir_aktif_pada')->nullable()->comment('Panggilan GET /anjungan terakhir (FR-01-30)');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE kunjungan.anjungan ADD CONSTRAINT anjungan_cek CHECK (mode IN ('pendaftaran', 'farmasi') AND status IN ('aktif', 'nonaktif') AND batas_diam_detik BETWEEN 15 AND 600 AND area_loket ~ '^[a-z0-9][a-z0-9_-]{0,39}$')");

        Schema::create('kunjungan.sesi_anjungan', function (Blueprint $table) {
            $table->comment('Jejak layanan per sesi anjungan (7.01.1, FR-01-30), tanpa identitas pasien. Hanya ditambah.');
            $table->uuid('id')->primary();
            $table->foreignUuid('anjungan_id')->constrained('kunjungan.anjungan')->restrictOnDelete();
            $table->timestampTz('mulai_pada');
            $table->timestampTz('selesai_pada')->nullable();
            $table->text('layanan');
            $table->text('hasil')->nullable();
            $table->text('alasan_ke_loket')->nullable();
            $table->foreignUuid('kunjungan_id')->nullable()->constrained('kunjungan.kunjungan')->restrictOnDelete();

            $table->index(['anjungan_id', 'mulai_pada']);
        });
        DB::statement("ALTER TABLE kunjungan.sesi_anjungan ADD CONSTRAINT sesi_anjungan_cek CHECK (hasil IS NULL OR hasil IN ('selesai', 'ke_loket', 'batal'))");
        DB::statement('REVOKE UPDATE ON kunjungan.sesi_anjungan FROM simrs_app');
    }

    public function down(): void
    {
        Schema::dropIfExists('kunjungan.sesi_anjungan');
        Schema::dropIfExists('kunjungan.anjungan');
        DB::statement('ALTER TABLE kunjungan.antrean DROP CONSTRAINT antrean_alasan_prioritas_cek');
        Schema::table('kunjungan.antrean', function (Blueprint $table) {
            $table->dropIndex(['titik', 'tanggal', 'urutan', 'id']);
            $table->dropColumn('alasan_prioritas');
        });
    }
};
