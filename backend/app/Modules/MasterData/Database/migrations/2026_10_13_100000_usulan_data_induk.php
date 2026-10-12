<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Usulan perubahan data induk berjenjang dan riwayat versi (tiket 1.8c; FR-54-01, FR-54-02, FR-08-16). Tidak ada DELETE:
 * usulan berpindah tahap, versi hanya ditambah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inti.alur_usulan', function (Blueprint $table) {
            $table->comment('Alur usulan per jenis data induk (FR-54-01): peran pengusul (pemilik data), pemverifikasi, penyetuju; kosong = siapa pun pemegang izinnya. Data induk RS, perlu verifikasi.');
            $table->uuid('id')->primary();
            $table->text('jenis_data')->unique();
            $table->foreignUuid('peran_pengusul_id')->nullable()->constrained('akses.peran')->restrictOnDelete();
            $table->foreignUuid('peran_pemverifikasi_id')->nullable()->constrained('akses.peran')->restrictOnDelete();
            $table->foreignUuid('peran_penyetuju_id')->nullable()->constrained('akses.peran')->restrictOnDelete();
            $table->kolomStandar();
        });
        DB::table('inti.alur_usulan')->insert(['id' => (string) Str::uuid7(), 'jenis_data' => 'ruang_tt']);

        DB::statement('CREATE SEQUENCE inti.usulan_data_induk_nomor_seq');
        Schema::create('inti.usulan_data_induk', function (Blueprint $table) {
            $table->comment('Usulan perubahan data induk (FR-54-02). Butir perubahan beserta nilai lama dan revisi objek saat diusulkan.');
            $table->uuid('id')->primary();
            $table->text('nomor')->unique()->comment('UDI-<tahun>-<urut>');
            $table->text('jenis_data');
            $table->jsonb('perubahan')->comment('Putaran terakhir: [{objek_jenis, aksi, objek_id, objek_label, nilai_lama, nilai_baru, revisi_objek, objek_id_hasil}]');
            $table->text('ringkasan')->comment('Dipotret saat diusulkan/dikirim ulang');
            $table->date('berlaku_dari');
            $table->text('alasan');
            $table->text('dasar');
            $table->text('tahap')->default('diusulkan');
            $table->integer('putaran')->default(1);
            $table->foreignUuid('diusulkan_oleh')->constrained('akses.pengguna')->restrictOnDelete();
            $table->timestampTz('diusulkan_pada', 6)->comment('Putaran terakhir (dibuat_pada = pertama kali)');
            $table->uuid('diverifikasi_oleh')->nullable();
            $table->timestampTz('diverifikasi_pada')->nullable();
            $table->uuid('disetujui_oleh')->nullable();
            $table->timestampTz('disetujui_pada')->nullable();
            $table->timestampTz('diterapkan_pada')->nullable();
            $table->text('gagal_alasan')->nullable();
            $table->kolomStandar();

            $table->index(['tahap', 'berlaku_dari']);
            $table->index(['dibuat_pada', 'id']);
        });
        DB::statement("ALTER TABLE inti.usulan_data_induk ADD CONSTRAINT usulan_tahap_cek CHECK (tahap IN ('diusulkan', 'diverifikasi', 'disetujui', 'berlaku', 'dikembalikan', 'gagal_diterapkan'))");
        DB::statement("ALTER TABLE inti.usulan_data_induk ADD CONSTRAINT usulan_jenis_cek CHECK (jenis_data IN ('ruang_tt'))");
        DB::statement('CREATE INDEX usulan_data_induk_perubahan_gin ON inti.usulan_data_induk USING gin (perubahan jsonb_path_ops)');

        Schema::create('inti.usulan_data_induk_putaran', function (Blueprint $table) {
            $table->comment('Isi tiap putaran usulan (kirim ulang tidak menimpa; jejak PRD 7.57.2 c–d). Hanya ditambah.');
            $table->uuid('id')->primary();
            $table->foreignUuid('usulan_id')->constrained('inti.usulan_data_induk')->restrictOnDelete();
            $table->integer('putaran');
            $table->jsonb('perubahan');
            $table->date('berlaku_dari');
            $table->text('alasan');
            $table->text('dasar');
            $table->text('ringkasan');
            $table->timestampTz('diajukan_pada', 6);

            $table->unique(['usulan_id', 'putaran']);
        });

        Schema::create('inti.usulan_data_induk_kejadian', function (Blueprint $table) {
            $table->comment('Riwayat tahap usulan (siapa, apa, kapan, catatan). Hanya ditambah.');
            $table->uuid('id')->primary();
            $table->foreignUuid('usulan_id')->constrained('inti.usulan_data_induk')->restrictOnDelete();
            $table->text('aksi');
            $table->uuid('oleh')->nullable()->comment('Kosong = sistem (penerapan terjadwal)');
            $table->timestampTz('pada', 6);
            $table->text('catatan')->nullable();
            $table->integer('putaran');

            $table->index(['usulan_id', 'pada']);
        });

        Schema::create('inti.data_induk_versi', function (Blueprint $table) {
            $table->comment('Versi data induk (FR-54-02): isi sebelum/sesudah setiap perubahan, lewat usulan maupun langsung. Hanya ditambah.');
            $table->uuid('id')->primary();
            $table->text('objek_jenis')->comment('ruang | kamar | tempat_tidur');
            $table->uuid('objek_id');
            $table->integer('versi');
            $table->jsonb('sebelum')->nullable();
            $table->jsonb('sesudah');
            $table->date('berlaku_dari');
            $table->foreignUuid('usulan_id')->nullable()->constrained('inti.usulan_data_induk')->restrictOnDelete();
            $table->text('dasar');
            $table->uuid('dibuat_oleh')->nullable();
            $table->timestampTz('dibuat_pada', 6)->useCurrent();

            $table->unique(['objek_jenis', 'objek_id', 'versi']);
        });

        // Tugas usulan menunggu (1.7): tenggat 2 hari, angka awal sama dengan jenis bawaan lain (diubah RS di layar).
        $sekarang = now();
        DB::table('inti.jenis_tugas')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'kode' => 'USULAN_DATA_INDUK', 'nama' => 'Usulan perubahan data induk menunggu',
            'uraian' => 'Verifikasi, persetujuan, atau perbaikan usulan perubahan data induk (FR-54-02).', 'modul' => 'master_data',
            'objek_jenis' => 'usulan_data_induk', 'tautan_layar' => '/master-data/usulan?usulan={objek_id}', 'tenggat_dari' => 'dibuat',
            'batas_menit' => 2880, 'pengingat_menit' => -240, 'teguran_menit' => 0, 'eskalasi_menit' => 1440,
            'kritis' => false, 'aktif' => true, 'dibuat_pada' => $sekarang, 'diubah_pada' => $sekarang,
        ]);

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        DB::table('inti.jenis_tugas')->where('kode', 'USULAN_DATA_INDUK')->delete();
        Schema::dropIfExists('inti.data_induk_versi');
        Schema::dropIfExists('inti.usulan_data_induk_kejadian');
        Schema::dropIfExists('inti.usulan_data_induk_putaran');
        Schema::dropIfExists('inti.usulan_data_induk');
        DB::statement('DROP SEQUENCE IF EXISTS inti.usulan_data_induk_nomor_seq');
        Schema::dropIfExists('inti.alur_usulan');
    }
};
