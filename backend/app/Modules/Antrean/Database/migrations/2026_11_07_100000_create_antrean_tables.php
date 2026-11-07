<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Antrean poli dan display (tiket 2.8; PRD 7.02.1, 11.2, FR-02-01/03/04/08/09/11/15/16). Baris antrean tidak dihapus:
 * batal = status beserta alasan, pelaku, dan waktunya. Setiap transisi tercatat di antrean_jejak dengan jam server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inti.poliklinik', function (Blueprint $table) {
            $table->text('antrean_prefiks')->nullable()->comment('Prefiks nomor antrean (FR-02-01); null = kode poli');
            $table->text('antrean_area')->nullable()->comment('Kode area display; null = kode poli huruf kecil');
            $table->text('nama_netral')->nullable()->comment('Terisi = poli sensitif: nama ini yang tampil di display dan suara (FR-02-15)');
        });
        // Poli sensitif wajib prefiks dan area sendiri: bawaan dari kode poli bisa membuka nama aslinya di display.
        DB::statement('ALTER TABLE inti.poliklinik ADD CONSTRAINT poliklinik_antrean_netral_cek CHECK (nama_netral IS NULL OR (antrean_prefiks IS NOT NULL AND antrean_area IS NOT NULL))');

        Schema::create('kunjungan.aturan_antrean', function (Blueprint $table) {
            $table->comment('Aturan panggil ulang, lewati, sisip (7.02.1, FR-02-11), satu baris. Nilai awal ditetapkan kebijakan RS.');
            $table->uuid('id')->primary();
            $table->boolean('tunggal')->default(true)->unique()->comment('Menjaga satu baris');
            $table->smallInteger('batas_panggilan')->default(3);
            $table->integer('jeda_panggilan_detik')->default(60);
            $table->smallInteger('sisip_setelah')->default(2)->comment('Nomor menunggu yang didahulukan saat disisipkan kembali');
            $table->kolomStandar();
        });
        DB::statement('ALTER TABLE kunjungan.aturan_antrean ADD CONSTRAINT aturan_antrean_cek CHECK (tunggal AND batas_panggilan >= 1 AND jeda_panggilan_detik >= 0 AND sisip_setelah >= 0)');
        DB::table('kunjungan.aturan_antrean')->insert(['id' => (string) Str::uuid7()]);

        Schema::create('kunjungan.nomor_antrean', function (Blueprint $table) {
            $table->comment('Penghitung nomor antrean per tanggal per prefiks. Dinaikkan saat antrean dibuat.');
            $table->date('tanggal');
            $table->text('prefiks');
            $table->integer('terakhir')->default(0);
            $table->primary(['tanggal', 'prefiks']);
        });

        Schema::create('kunjungan.antrean', function (Blueprint $table) {
            $table->comment('Nomor antrean per titik (7.02.1). Tiket 2.8: titik poli, satu nomor untuk perawat dan dokter.');
            $table->uuid('id')->primary();
            $table->date('tanggal')->comment('Zona RS');
            $table->foreignUuid('kunjungan_id')->nullable()->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->text('titik');
            $table->text('area')->comment('Kode area display');
            $table->text('prefiks');
            $table->integer('nomor')->comment('Urut per prefiks per hari');
            $table->text('nomor_tampil');
            $table->foreignUuid('poli_id')->nullable()->constrained('inti.poliklinik')->restrictOnDelete();
            $table->foreignUuid('pegawai_id')->nullable()->comment('Dokter jadwal')->constrained('sdm.pegawai')->restrictOnDelete();
            $table->foreignUuid('ruang_id')->nullable()->comment('Ruang/loket panggilan terakhir')->constrained('inti.lokasi')->restrictOnDelete();
            $table->text('sumber')->default('loket');
            $table->boolean('prioritas')->default(false);
            $table->text('status')->default('menunggu');
            $table->decimal('urutan', 20, 10)->comment('Urutan tunggu; sisip = nilai di antara dua nomor');
            $table->smallInteger('panggilan_ke')->default(0)->comment('Panggilan sejak terakhir menunggu');
            $table->timestampTz('dipanggil_pada')->nullable()->comment('Panggilan terakhir');
            $table->text('alasan_batal')->nullable();
            $table->uuid('dibatalkan_oleh')->nullable()->comment('akses.pengguna.id; null = sistem');
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->unique(['kunjungan_id', 'titik']);
            $table->unique(['tanggal', 'titik', 'prefiks', 'nomor']);
            $table->index(['poli_id', 'tanggal', 'urutan', 'id']);
            $table->index(['area', 'tanggal', 'status']);
        });
        DB::statement("ALTER TABLE kunjungan.antrean ADD CONSTRAINT antrean_cek CHECK (titik IN ('loket', 'perawat', 'poli', 'lab', 'radiologi', 'farmasi', 'kasir') AND sumber IN ('loket', 'mobile_jkn', 'apm', 'web') AND status IN ('belum_check_in', 'menunggu', 'dipanggil', 'dilayani', 'dilewati', 'kembali', 'selesai', 'batal') AND panggilan_ke >= 0 AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL AND alasan_batal IS NOT NULL)))");

        Schema::create('kunjungan.antrean_jejak', function (Blueprint $table) {
            $table->comment('Setiap transisi status antrean dengan jam server (FR-02-16, dasar waktu tunggu INM). Hanya ditambah.');
            $table->uuid('id')->primary();
            $table->foreignUuid('antrean_id')->constrained('kunjungan.antrean')->restrictOnDelete();
            $table->text('status');
            $table->smallInteger('panggilan_ke')->nullable()->comment('Hanya status dipanggil');
            $table->foreignUuid('ruang_id')->nullable()->constrained('inti.lokasi')->restrictOnDelete();
            $table->timestampTz('terjadi_pada')->useCurrent();
            $table->uuid('oleh')->nullable()->comment('akses.pengguna.id; null = sistem');

            $table->index(['antrean_id', 'terjadi_pada']);
        });

        Schema::create('kunjungan.display_antrean', function (Blueprint $table) {
            $table->comment('Perangkat display antrean (PRD 11.2, 7.01.1): perangkat, bukan akun orang. Token di sistem.personal_access_tokens (hash).');
            $table->uuid('id')->primary();
            $table->text('kode')->unique();
            $table->jsonb('area')->default('[]')->comment('Kode area yang boleh dibaca; [] = semua area');
            $table->text('status')->default('aktif');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE kunjungan.display_antrean ADD CONSTRAINT display_antrean_cek CHECK (status IN ('aktif', 'nonaktif') AND jsonb_typeof(area) = 'array')");
    }

    public function down(): void
    {
        Schema::dropIfExists('kunjungan.display_antrean');
        Schema::dropIfExists('kunjungan.antrean_jejak');
        Schema::dropIfExists('kunjungan.antrean');
        Schema::dropIfExists('kunjungan.nomor_antrean');
        Schema::dropIfExists('kunjungan.aturan_antrean');
        DB::statement('ALTER TABLE inti.poliklinik DROP CONSTRAINT poliklinik_antrean_netral_cek');
        Schema::table('inti.poliklinik', fn (Blueprint $table) => $table->dropColumn(['antrean_prefiks', 'antrean_area', 'nama_netral']));
    }
};
