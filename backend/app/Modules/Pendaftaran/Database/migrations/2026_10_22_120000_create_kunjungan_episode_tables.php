<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Episode perawatan dan kunjungan poli (tiket 2.6; PRD 7.00.2, FR-00-01, FR-01-06/15/21). Tidak dihapus: pembatalan
 * lewat status `batal` beserta alasan, pelaku, dan waktunya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kunjungan.episode', function (Blueprint $table) {
            $table->comment('Episode perawatan (7.00.2). Rawat jalan harian: satu per pasien per tanggal; batal bila semua kunjungannya batal.');
            $table->uuid('id')->primary();
            $table->foreignUuid('pasien_id')->constrained('pasien.pasien')->restrictOnDelete();
            $table->text('jenis');
            $table->date('mulai_tanggal')->comment('Zona RS');
            $table->text('status')->default('aktif');
            $table->text('alasan_batal')->nullable();
            $table->uuid('dibatalkan_oleh')->nullable()->comment('akses.pengguna.id; null = sistem');
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->index(['pasien_id', 'mulai_tanggal']);
        });
        DB::statement("ALTER TABLE kunjungan.episode ADD CONSTRAINT episode_cek CHECK (jenis IN ('rawat_jalan_harian') AND status IN ('aktif', 'selesai', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX episode_rawat_jalan_harian_unik ON kunjungan.episode (pasien_id, mulai_tanggal) WHERE jenis = 'rawat_jalan_harian' AND status <> 'batal'");

        Schema::create('kunjungan.nomor_kunjungan', function (Blueprint $table) {
            $table->comment('Penghitung nomor kunjungan per tanggal (RJ-YYYYMMDD-NNNN). Dinaikkan di transaksi pendaftaran.');
            $table->date('tanggal')->primary();
            $table->integer('terakhir')->default(0);
        });

        Schema::create('kunjungan.kunjungan', function (Blueprint $table) {
            $table->comment('Kunjungan per pos (7.00.2). Tahap ini hanya poliklinik lewat jadwal praktik; kuota dihitung dari baris tidak batal.');
            $table->uuid('id')->primary();
            $table->text('nomor')->unique();
            $table->foreignUuid('pasien_id')->constrained('pasien.pasien')->restrictOnDelete();
            $table->foreignUuid('episode_id')->constrained('kunjungan.episode')->restrictOnDelete();
            $table->text('jenis_kunjungan');
            $table->foreignUuid('jadwal_praktik_id')->constrained('kunjungan.jadwal_praktik')->restrictOnDelete();
            $table->foreignUuid('poli_id')->constrained('inti.poliklinik')->restrictOnDelete();
            $table->foreignUuid('pegawai_id')->comment('Dokter jadwal')->constrained('sdm.pegawai')->restrictOnDelete();
            $table->date('tanggal')->comment('Zona RS');
            $table->text('cara_bayar');
            $table->foreignUuid('penjamin_id')->nullable()->constrained('inti.penjamin')->restrictOnDelete();
            $table->text('status')->default('terdaftar');
            $table->text('keluhan')->nullable()->comment('KLINIS: keluhan awal; tidak pernah di log/audit');
            $table->text('alasan_batal')->nullable();
            $table->uuid('dibatalkan_oleh')->nullable()->comment('akses.pengguna.id; null = sistem (batal otomatis jam tutup)');
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->index(['poli_id', 'tanggal', 'dibuat_pada', 'id']);
            $table->index(['jadwal_praktik_id', 'tanggal', 'cara_bayar']);
            $table->index(['episode_id']);
            $table->index(['pasien_id', 'status']);
            $table->index(['status', 'tanggal']);
        });
        DB::statement("ALTER TABLE kunjungan.kunjungan ADD CONSTRAINT kunjungan_cek CHECK (jenis_kunjungan IN ('poliklinik') AND cara_bayar IN ('jkn', 'non_jkn') AND status IN ('terdaftar', 'dilayani', 'selesai', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL AND alasan_batal IS NOT NULL)) AND (cara_bayar = 'non_jkn' OR penjamin_id IS NOT NULL))");
        // Satu kunjungan aktif per pasien per jadwal per tanggal (409 kunjungan_ganda; penjaga balapan).
        DB::statement("CREATE UNIQUE INDEX kunjungan_aktif_unik ON kunjungan.kunjungan (pasien_id, jadwal_praktik_id, tanggal) WHERE status <> 'batal'");

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        Schema::dropIfExists('kunjungan.kunjungan');
        Schema::dropIfExists('kunjungan.nomor_kunjungan');
        Schema::dropIfExists('kunjungan.episode');
    }
};
