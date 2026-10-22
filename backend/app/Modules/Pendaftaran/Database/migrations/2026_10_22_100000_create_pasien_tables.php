<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas pasien (tiket 2.5; PRD 11.2 skema `pasien`, FR-01-01/04/05). Pasien tidak pernah dihapus; perubahan identitas
 * menyimpan versi sebelumnya di pasien_versi. NIK, paspor, No. kartu, telepon, dan alamat terenkripsi aplikasi; NIK dan
 * No. kartu dicari lewat sidik HMAC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pasien.nomor_rm', function (Blueprint $table) {
            $table->comment('Penghitung No. RM (satu baris). Dinaikkan di transaksi pendaftaran: batal = ikut batal, jadi tanpa celah.');
            $table->smallInteger('id')->primary();
            $table->bigInteger('terakhir')->default(0);
        });
        DB::statement('ALTER TABLE pasien.nomor_rm ADD CONSTRAINT nomor_rm_satu_baris CHECK (id = 1 AND terakhir >= 0)');
        DB::table('pasien.nomor_rm')->insert(['id' => 1, 'terakhir' => 0]);

        Schema::create('pasien.pasien', function (Blueprint $table) {
            $table->comment('Pasien RS dengan No. RM seumur hidup. Tidak dihapus; versi sebelumnya di pasien.pasien_versi.');
            $table->uuid('id')->primary();
            $table->text('no_rm')->unique()->comment('PRIBADI: angka berurutan dari pasien.nomor_rm; tidak bisa diubah (trigger)');
            $table->text('nama')->comment('PRIBADI');
            $table->date('tanggal_lahir')->nullable()->comment('PRIBADI');
            $table->text('tempat_lahir')->nullable()->comment('PRIBADI');
            $table->text('jenis_kelamin');
            $table->text('agama')->nullable()->comment('Kode inti.referensi kelompok agama');
            $table->text('pendidikan')->nullable()->comment('Kode inti.referensi kelompok pendidikan');
            $table->text('pekerjaan')->nullable()->comment('Kode inti.referensi kelompok pekerjaan');
            $table->text('status_kawin')->nullable()->comment('Kode inti.referensi kelompok status_kawin');
            $table->text('kewarganegaraan')->default('wni');
            $table->text('bahasa')->nullable();
            $table->text('kebutuhan_khusus')->nullable();
            $table->text('alamat_enkripsi')->nullable()->comment('PRIBADI: {jalan, rt, rw, kode_pos} terenkripsi aplikasi');
            $table->foreignUuid('wilayah_id')->nullable()->comment('Kelurahan/desa')->constrained('inti.wilayah')->restrictOnDelete();
            $table->text('telepon_enkripsi')->nullable()->comment('PRIBADI: terenkripsi aplikasi');
            $table->boolean('tanpa_identitas')->default(false)->comment('Mr. X / Ny. X (FR-01-05); hanya bisa berubah ke false');
            $table->text('ciri_pengenal')->nullable()->comment('PRIBADI: ciri fisik, tempat dan waktu ditemukan, pengantar');
            $table->timestampTz('meninggal_pada')->nullable();
            $table->kolomStandar();

            $table->index(['dibuat_pada', 'id']);
            $table->index(['tanggal_lahir', 'dibuat_pada', 'id']);
        });
        DB::statement("ALTER TABLE pasien.pasien ADD CONSTRAINT pasien_cek CHECK (jenis_kelamin IN ('laki_laki', 'perempuan', 'tidak_diketahui') AND kewarganegaraan IN ('wni', 'wna') AND no_rm ~ '^[0-9]{1,12}$' AND (tanpa_identitas OR (tanggal_lahir IS NOT NULL AND jenis_kelamin <> 'tidak_diketahui')))");
        // Cari nama + tanggal lahir (FR-01-01): kemiripan kata lewat trigram.
        DB::statement('CREATE INDEX pasien_nama_trgm ON pasien.pasien USING gin (nama gin_trgm_ops)');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION pasien.no_rm_tetap() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.no_rm IS DISTINCT FROM OLD.no_rm THEN
                    RAISE EXCEPTION 'No. RM tidak bisa diubah' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER pasien_no_rm_tetap BEFORE UPDATE OF no_rm ON pasien.pasien
                FOR EACH ROW EXECUTE FUNCTION pasien.no_rm_tetap();
            SQL);

        Schema::create('pasien.identitas', function (Blueprint $table) {
            $table->comment('Nomor identitas pasien: NIK, paspor, kartu BPJS. Satu baris per jenis; dikosongkan = nilai null (jejak di pasien_versi).');
            $table->uuid('id')->primary();
            $table->foreignUuid('pasien_id')->constrained('pasien.pasien')->restrictOnDelete();
            $table->text('jenis');
            $table->text('nilai_enkripsi')->nullable()->comment('PRIBADI: terenkripsi aplikasi; tidak pernah di log/audit');
            $table->char('sidik', 64)->nullable()->comment('HMAC-SHA256 nilai untuk cari dan keunikan');
            $table->kolomStandar();

            $table->unique(['pasien_id', 'jenis']);
        });
        DB::statement("ALTER TABLE pasien.identitas ADD CONSTRAINT identitas_cek CHECK (jenis IN ('nik', 'paspor', 'kartu_bpjs') AND ((nilai_enkripsi IS NULL) = (sidik IS NULL)))");
        // NIK unik per pasien (aturan bisnis modul 01) dan No. kartu BPJS milik satu pasien; paspor tidak dijamin unik antarnegara.
        DB::statement("CREATE UNIQUE INDEX identitas_sidik_unik ON pasien.identitas (jenis, sidik) WHERE sidik IS NOT NULL AND jenis IN ('nik', 'kartu_bpjs')");

        Schema::create('pasien.kontak_darurat', function (Blueprint $table) {
            $table->comment('Kontak darurat pasien (≤ 3 aktif). Daftar diganti = baris lama aktif false, tidak dihapus.');
            $table->uuid('id')->primary();
            $table->foreignUuid('pasien_id')->constrained('pasien.pasien')->restrictOnDelete();
            $table->smallInteger('urutan');
            $table->text('nama')->comment('PRIBADI');
            $table->text('hubungan');
            $table->text('telepon_enkripsi')->comment('PRIBADI: terenkripsi aplikasi');
            $table->boolean('aktif')->default(true);
            $table->timestampTz('diganti_pada')->nullable();
            $table->kolomStandar();

            $table->index(['pasien_id', 'aktif', 'urutan']);
        });

        Schema::create('pasien.pasien_versi', function (Blueprint $table) {
            $table->comment('Jejak perubahan identitas (Permenkes 24/2022 Ps. 30): isi pasien sebelum perubahan, terenkripsi, dengan alasannya.');
            $table->uuid('id')->primary();
            $table->foreignUuid('pasien_id')->constrained('pasien.pasien')->restrictOnDelete();
            $table->integer('revisi')->comment('Revisi pasien yang digantikan');
            $table->text('isi_enkripsi')->comment('PRIBADI: isi pasien (termasuk identitas dan kontak darurat) sebelum perubahan');
            $table->text('alasan_perubahan');
            $table->timestampTz('dibuat_pada')->useCurrent();
            $table->uuid('dibuat_oleh')->nullable()->comment('akses.pengguna.id');

            $table->unique(['pasien_id', 'revisi']);
        });

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    public function down(): void
    {
        Schema::dropIfExists('pasien.pasien_versi');
        Schema::dropIfExists('pasien.kontak_darurat');
        Schema::dropIfExists('pasien.identitas');
        Schema::dropIfExists('pasien.pasien');
        DB::unprepared('DROP FUNCTION IF EXISTS pasien.no_rm_tetap()');
        Schema::dropIfExists('pasien.nomor_rm');
    }
};
