<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-resep rawat jalan (tiket 2.17, kontrak v0.27.0; PRD 11.2 `farmasi`, FR-07-06, FR-07-07, FR-02-10). Tanpa DELETE:
 * pembatalan = status batal + alasan; butir draf yang diganti PATCH ditandai `diganti_pada`. Asumsi PR kontrak:
 * `resep.pasien_id`, `penulis_id`, `antrean_id`, `tanda_tangan_id`; `resep_item.frekuensi_per_hari`, `jumlah_per_pakai`;
 * `peringatan_resep.kunci`, `pesan`, `tanggapan`, `ditanggapi_oleh/pada`. `resep.masuk_pada` = jam disahkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farmasi.nomor_resep', function (Blueprint $table) {
            $table->comment('Penghitung nomor resep per tanggal (zona RS).');
            $table->date('tanggal')->primary();
            $table->integer('terakhir')->default(0);
        });

        Schema::create('farmasi.resep', function (Blueprint $table) {
            $table->comment('Resep elektronik (7.22). Isi resep = data klinis: tidak ke log/audit.');
            $table->uuid('id')->primary();
            $table->text('no_resep')->unique();
            $table->foreignUuid('kunjungan_id')->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->foreignUuid('pasien_id')->constrained('pasien.pasien')->restrictOnDelete();
            $table->foreignUuid('depo_id')->comment('inti.unit_organisasi berjenis depo')->constrained('inti.unit_organisasi')->restrictOnDelete();
            $table->text('jenis')->default('rj');
            $table->boolean('racikan')->default(false);
            $table->text('catatan')->nullable()->comment('KLINIS: tidak pernah ke log/audit');
            $table->text('status')->default('draf');
            $table->uuid('penulis_id')->comment('akses.pengguna.id');
            $table->foreignUuid('dokter_id')->nullable()->comment('Pegawai yang mengesahkan')->constrained('sdm.pegawai')->restrictOnDelete();
            $table->timestampTz('masuk_pada')->nullable()->comment('Jam disahkan = masuk antrean farmasi');
            $table->foreignUuid('antrean_id')->nullable()->constrained('kunjungan.antrean')->restrictOnDelete();
            $table->foreignUuid('tanda_tangan_id')->nullable()->constrained('ttd.tanda_tangan')->restrictOnDelete();
            $table->text('alasan_batal')->nullable()->comment('KLINIS: tidak pernah ke log/audit');
            $table->uuid('dibatalkan_oleh')->nullable();
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->foreign('penulis_id')->references('id')->on('akses.pengguna')->restrictOnDelete();
            $table->index(['kunjungan_id', 'dibuat_pada', 'id']);
            $table->index(['pasien_id', 'masuk_pada']);
            $table->index(['depo_id', 'masuk_pada', 'id']);
        });
        DB::statement("ALTER TABLE farmasi.resep ADD CONSTRAINT resep_cek CHECK (jenis IN ('rj', 'ri', 'pulang', 'igd') AND status IN ('draf', 'sah', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL AND alasan_batal IS NOT NULL)) AND (status = 'draf' OR status = 'batal' OR (masuk_pada IS NOT NULL AND dokter_id IS NOT NULL)))");

        Schema::create('farmasi.resep_item', function (Blueprint $table) {
            $table->comment('Butir resep. Butir draf yang diganti PATCH ditandai diganti_pada (tidak dihapus).');
            $table->uuid('id')->primary();
            $table->foreignUuid('resep_id')->constrained('farmasi.resep')->restrictOnDelete();
            $table->foreignUuid('barang_id')->constrained('logistik.barang')->restrictOnDelete();
            $table->smallInteger('urutan');
            $table->text('dosis')->nullable();
            $table->smallInteger('frekuensi_per_hari');
            $table->decimal('jumlah_per_pakai', 12, 3);
            $table->smallInteger('hari');
            $table->decimal('jumlah', 12, 3);
            $table->text('rute')->nullable();
            $table->text('aturan_pakai')->nullable();
            $table->text('catatan')->nullable()->comment('KLINIS: tidak pernah ke log/audit');
            $table->timestampTz('diganti_pada')->nullable();
            $table->kolomStandar();

            $table->index(['resep_id', 'urutan']);
            $table->index('barang_id');
        });
        DB::statement('ALTER TABLE farmasi.resep_item ADD CONSTRAINT resep_item_cek CHECK (frekuensi_per_hari BETWEEN 1 AND 24 AND jumlah_per_pakai > 0 AND hari BETWEEN 1 AND 365 AND jumlah > 0)');

        Schema::create('farmasi.peringatan_resep', function (Blueprint $table) {
            $table->comment('Peringatan pemeriksaan resep (FR-07-07) yang ditanggapi dokter saat mengesahkan. Hanya ditambah.');
            $table->uuid('id')->primary();
            $table->foreignUuid('resep_item_id')->constrained('farmasi.resep_item')->restrictOnDelete();
            $table->text('kunci');
            $table->text('jenis');
            $table->text('tingkat')->nullable();
            $table->text('pesan')->comment('KLINIS: tidak pernah ke log/audit');
            $table->text('tanggapan')->comment('KLINIS: alasan tetap meresepkan, tidak pernah ke log/audit');
            $table->uuid('ditanggapi_oleh')->comment('akses.pengguna.id');
            $table->timestampTz('ditanggapi_pada');

            $table->unique(['resep_item_id', 'kunci']);
        });
        DB::statement("ALTER TABLE farmasi.peringatan_resep ADD CONSTRAINT peringatan_resep_cek CHECK (jenis IN ('alergi', 'interaksi', 'dosis_ginjal', 'duplikasi', 'non_fornas'))");
        DB::statement('REVOKE UPDATE ON farmasi.peringatan_resep FROM simrs_app');

        Schema::table('kunjungan.aturan_antrean', function (Blueprint $table) {
            $table->text('prefiks_farmasi')->default('F')->comment('Prefiks nomor antrean farmasi (7.02.1); kebijakan RS');
        });
    }

    public function down(): void
    {
        Schema::table('kunjungan.aturan_antrean', fn (Blueprint $table) => $table->dropColumn('prefiks_farmasi'));
        Schema::dropIfExists('farmasi.peringatan_resep');
        Schema::dropIfExists('farmasi.resep_item');
        Schema::dropIfExists('farmasi.resep');
        Schema::dropIfExists('farmasi.nomor_resep');
    }
};
