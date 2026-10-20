<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Buku tarif berversi (tiket 2.2, FR-28-12, FR-28-13) dan alur usulan jenis `tarif`. Tidak ada DELETE: perubahan = versi
 * baru, penutupan = versi `nonaktif`. Mesin usulan 1.8c diperluas: CHECK usulan_jenis_cek + baris inti.alur_usulan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keuangan.tarif', function (Blueprint $table) {
            $table->comment('Identitas butir buku tarif (kode unik). Nama, kelompok, status, dan harga ada di tarif_versi; versi berlaku = berlaku_dari terbaru yang ≤ tanggal transaksi.');
            $table->uuid('id')->primary();
            $table->text('kode')->unique();
            $table->kolomStandar();
        });

        Schema::create('keuangan.tarif_versi', function (Blueprint $table) {
            $table->comment('Versi butir tarif (FR-28-13): hanya ditambah, transaksi sebelum tanggal berlaku memakai versi lama.');
            $table->uuid('id')->primary();
            $table->foreignUuid('tarif_id')->constrained('keuangan.tarif')->restrictOnDelete();
            $table->integer('versi');
            $table->text('nama');
            $table->text('kelompok');
            $table->text('status')->default('aktif');
            $table->date('berlaku_dari');
            $table->uuid('usulan_id')->nullable()->comment('inti.usulan_data_induk.id; kosong = pengisian langsung saat penyiapan');
            $table->text('dasar');
            $table->uuid('dibuat_oleh')->nullable()->comment('akses.pengguna.id');
            $table->timestampTz('dibuat_pada', 6)->useCurrent();

            $table->unique(['tarif_id', 'versi']);
            $table->index(['tarif_id', 'berlaku_dari']);
            $table->foreign('usulan_id')->references('id')->on('inti.usulan_data_induk')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE keuangan.tarif_versi ADD CONSTRAINT tarif_versi_status_cek CHECK (status IN ('aktif', 'nonaktif'))");

        Schema::create('keuangan.tarif_versi_harga', function (Blueprint $table) {
            $table->comment('Harga per kelas rawat pada satu versi tarif: jasa sarana, jasa pelayanan, BHP (rupiah, numeric).');
            $table->uuid('id')->primary();
            $table->foreignUuid('tarif_versi_id')->constrained('keuangan.tarif_versi')->restrictOnDelete();
            $table->foreignUuid('kelas_rawat_id')->constrained('inti.kelas_rawat')->restrictOnDelete();
            $table->decimal('jasa_sarana', 15, 2);
            $table->decimal('jasa_pelayanan', 15, 2);
            $table->decimal('bhp', 15, 2);

            $table->unique(['tarif_versi_id', 'kelas_rawat_id']);
        });
        DB::statement('ALTER TABLE keuangan.tarif_versi_harga ADD CONSTRAINT tarif_versi_harga_nonnegatif_cek CHECK (jasa_sarana >= 0 AND jasa_pelayanan >= 0 AND bhp >= 0)');

        DB::statement('ALTER TABLE inti.usulan_data_induk DROP CONSTRAINT usulan_jenis_cek');
        DB::statement("ALTER TABLE inti.usulan_data_induk ADD CONSTRAINT usulan_jenis_cek CHECK (jenis_data IN ('ruang_tt', 'tarif'))");
        DB::table('inti.alur_usulan')->insertOrIgnore(['id' => (string) Str::uuid7(), 'jenis_data' => 'tarif']);

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        DB::table('inti.alur_usulan')->where('jenis_data', 'tarif')->delete();
        DB::statement('ALTER TABLE inti.usulan_data_induk DROP CONSTRAINT usulan_jenis_cek');
        DB::statement("ALTER TABLE inti.usulan_data_induk ADD CONSTRAINT usulan_jenis_cek CHECK (jenis_data IN ('ruang_tt'))");
        Schema::dropIfExists('keuangan.tarif_versi_harga');
        Schema::dropIfExists('keuangan.tarif_versi');
        Schema::dropIfExists('keuangan.tarif');
    }
};
