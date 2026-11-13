<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lab: hasil, nilai rujukan data induk, validasi, hasil mengikuti pasien (tiket 2.31; FR-19-02, FR-19-05, FR-19-06,
 * FR-00-09, PRD 7.00.5 butir 2–3, PRD 11.2 `lab.hasil`).
 * - Status butir order + `menunggu_validasi`, `menunggu_otorisasi` (Sp.PK), `divalidasi`.
 * - lab.pemeriksaan / lab.nilai_rujukan: data induk per butir tarif lab. Tabel kosong di produksi: diisi RS (layar/usulan
 *   data induk menyusul); lokal/CI diisi seeder sintetis. Tanpa angka klinis di migrasi. Tanpa DELETE: nonaktif.
 *   Rentang umur setengah terbuka [umur_min_hari, umur_maks_hari); umur_maks_hari null = tanpa batas; jenis_kelamin null =
 *   semua. Rentang aktif yang tumpang tindih untuk pemeriksaan + JK yang sama ditolak trigger (alasan di up()).
 * - lab.hasil: satu hasil per butir order (panel menyusul). Batas rujukan/kritis disalin dari baris nilai rujukan yang
 *   dipakai saat dihitung. Nilai tidak diubah sesudah `divalidasi`; koreksi = lab.hasil_adendum.
 * - Jenis tugas HASIL_LAB_TINDAK_LANJUT: angka tenggat = keputusan pemilik 13 Nov 2026, perlu verifikasi kebijakan RS
 *   (data jenis tugas, diubah RS di layar Jenis Tugas). Eskalasi nilai kritis = tiket 2.32.
 * - Aksi `sahkan` (validasi analis) dan `setujui` (otorisasi Sp.PK, bersyarat SIP/SPK) untuk penunjang.laboratorium
 *   lewat AKSI_TAMBAHAN; keduanya sudah dikenal izin_jenis_aksi_cek, jadi cek itu tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE permintaan.permintaan_item DROP CONSTRAINT permintaan_item_cek');
        DB::statement("ALTER TABLE permintaan.permintaan_item ADD CONSTRAINT permintaan_item_cek CHECK (status IN ('dipesan', 'sampel_diambil', 'diterima_lab', 'menunggu_validasi', 'menunggu_otorisasi', 'divalidasi', 'batal'))");
        // Daftar kerja tahap hasil/otorisasi: indeks parsial himpunan aktif (pola permintaan_item_aktif 2.30), tidak memuat
        // butir `divalidasi` yang terus bertambah.
        DB::statement("CREATE INDEX permintaan_item_hasil_aktif ON permintaan.permintaan_item (permintaan_pemeriksaan_id) WHERE status IN ('diterima_lab', 'menunggu_validasi', 'menunggu_otorisasi')");

        Schema::create('lab.pemeriksaan', function (Blueprint $table) {
            $table->comment('Data induk pemeriksaan lab per butir tarif (tiket 2.31). Diisi RS; tidak dihapus, nonaktif bila tidak dipakai.');
            $table->uuid('id')->primary();
            $table->foreignUuid('tarif_id')->unique()->constrained('keuangan.tarif')->restrictOnDelete();
            $table->text('kode_loinc')->nullable();
            $table->text('satuan')->nullable()->comment('UCUM');
            $table->text('jenis_hasil')->comment('numerik | teks');
            $table->boolean('butuh_otorisasi_spk')->default(false)->comment('Wajib otorisasi dokter Sp.PK sesudah validasi analis (FR-19-05)');
            $table->jsonb('nilai_normal_teks')->nullable()->comment('Daftar nilai normal untuk jenis teks (informasi)');
            $table->jsonb('nilai_kritis_teks')->nullable()->comment('Daftar nilai kritis untuk jenis teks; cocok = penanda kritis');
            $table->text('status')->default('aktif');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE lab.pemeriksaan ADD CONSTRAINT pemeriksaan_cek CHECK (jenis_hasil IN ('numerik', 'teks') AND status IN ('aktif', 'nonaktif')
            AND (nilai_normal_teks IS NULL OR jsonb_typeof(nilai_normal_teks) = 'array')
            AND (nilai_kritis_teks IS NULL OR jsonb_typeof(nilai_kritis_teks) = 'array'))");

        Schema::create('lab.nilai_rujukan', function (Blueprint $table) {
            $table->comment('Nilai rujukan dan batas kritis per pemeriksaan × umur × JK (FR-19-06). Diisi RS; tidak dihapus.');
            $table->uuid('id')->primary();
            $table->foreignUuid('pemeriksaan_id')->constrained('lab.pemeriksaan')->restrictOnDelete();
            $table->text('jenis_kelamin')->nullable()->comment('laki_laki | perempuan; null = semua');
            $table->integer('umur_min_hari')->default(0)->comment('Termasuk');
            $table->integer('umur_maks_hari')->nullable()->comment('Tidak termasuk; null = tanpa batas');
            $table->decimal('rujukan_min', 18, 6)->nullable();
            $table->decimal('rujukan_maks', 18, 6)->nullable();
            $table->decimal('kritis_min', 18, 6)->nullable();
            $table->decimal('kritis_maks', 18, 6)->nullable();
            $table->text('status')->default('aktif');
            $table->kolomStandar();

            $table->index(['pemeriksaan_id', 'status']);
        });
        DB::statement("ALTER TABLE lab.nilai_rujukan ADD CONSTRAINT nilai_rujukan_cek CHECK (status IN ('aktif', 'nonaktif')
            AND (jenis_kelamin IS NULL OR jenis_kelamin IN ('laki_laki', 'perempuan'))
            AND umur_min_hari >= 0 AND (umur_maks_hari IS NULL OR umur_maks_hari > umur_min_hari)
            AND (rujukan_min IS NULL OR rujukan_maks IS NULL OR rujukan_min <= rujukan_maks)
            AND (kritis_min IS NULL OR kritis_maks IS NULL OR kritis_min <= kritis_maks))");
        // Tumpang tindih ditolak trigger, bukan exclusion constraint: EXCLUDE dengan kesamaan uuid butuh ekstensi btree_gist
        // yang belum dipasang di instalasi. Kunci advisory per pemeriksaan menjaga dua penulisan bersamaan.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION lab.nilai_rujukan_tolak_tumpang_tindih() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.status <> 'aktif' THEN
        RETURN NEW;
    END IF;
    PERFORM pg_advisory_xact_lock(hashtext('lab.nilai_rujukan:' || NEW.pemeriksaan_id::text));
    IF EXISTS (
        SELECT 1 FROM lab.nilai_rujukan r
        WHERE r.pemeriksaan_id = NEW.pemeriksaan_id AND r.id <> NEW.id AND r.status = 'aktif'
          AND r.jenis_kelamin IS NOT DISTINCT FROM NEW.jenis_kelamin
          AND int4range(r.umur_min_hari, r.umur_maks_hari, '[)') && int4range(NEW.umur_min_hari, NEW.umur_maks_hari, '[)')
    ) THEN
        RAISE EXCEPTION 'Rentang umur nilai rujukan aktif tumpang tindih untuk pemeriksaan dan jenis kelamin yang sama'
            USING ERRCODE = '23P01';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER nilai_rujukan_tumpang_tindih BEFORE INSERT OR UPDATE ON lab.nilai_rujukan
    FOR EACH ROW EXECUTE FUNCTION lab.nilai_rujukan_tolak_tumpang_tindih();
SQL);

        Schema::create('lab.hasil', function (Blueprint $table) {
            $table->comment('Hasil lab per butir order (PRD 11.2, tiket 2.31). Tidak dihapus; nilai tetap sesudah divalidasi, koreksi = adendum.');
            $table->uuid('id')->primary();
            $table->foreignUuid('permintaan_item_id')->unique()->constrained('permintaan.permintaan_item')->restrictOnDelete();
            $table->foreignUuid('pemeriksaan_id')->constrained('lab.pemeriksaan')->restrictOnDelete();
            $table->foreignUuid('episode_id')->comment('Episode order (7.00.5 butir 1), disalin untuk linimasa')->constrained('kunjungan.episode')->restrictOnDelete();
            $table->decimal('nilai', 18, 6)->nullable();
            $table->text('nilai_teks')->nullable();
            $table->text('satuan')->nullable()->comment('Salinan data induk saat input');
            $table->text('kode_loinc')->nullable()->comment('Salinan data induk saat input');
            $table->foreignUuid('nilai_rujukan_id')->nullable()->constrained('lab.nilai_rujukan')->restrictOnDelete();
            $table->decimal('rujukan_min', 18, 6)->nullable();
            $table->decimal('rujukan_maks', 18, 6)->nullable();
            $table->decimal('kritis_min', 18, 6)->nullable();
            $table->decimal('kritis_maks', 18, 6)->nullable();
            $table->text('penanda')->nullable()->comment('l | h | ll | hh | kritis; dihitung server');
            $table->text('peringatan')->nullable()->comment('nilai_rujukan_tidak_ada');
            $table->text('catatan')->nullable();
            $table->text('status')->comment('menunggu_validasi | menunggu_otorisasi | divalidasi (sama dengan status butir)');
            $table->foreignUuid('diinput_oleh')->constrained('akses.pengguna')->restrictOnDelete();
            $table->timestampTz('diinput_pada', 6);
            $table->uuid('divalidasi_oleh')->nullable()->comment('Analis (akses.pengguna.id)');
            $table->timestampTz('divalidasi_pada', 6)->nullable();
            $table->uuid('diotorisasi_oleh')->nullable()->comment('Dokter Sp.PK (akses.pengguna.id)');
            $table->timestampTz('diotorisasi_pada', 6)->nullable();
            $table->timestampTz('dikeluarkan_pada', 6)->nullable()->comment('Jam hasil final (status divalidasi)');
            $table->kolomStandar();

            $table->foreign('divalidasi_oleh')->references('id')->on('akses.pengguna')->restrictOnDelete();
            $table->foreign('diotorisasi_oleh')->references('id')->on('akses.pengguna')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE lab.hasil ADD CONSTRAINT hasil_cek CHECK (
            status IN ('menunggu_validasi', 'menunggu_otorisasi', 'divalidasi')
            AND (penanda IS NULL OR penanda IN ('l', 'h', 'll', 'hh', 'kritis'))
            AND (peringatan IS NULL OR peringatan IN ('nilai_rujukan_tidak_ada'))
            AND ((nilai IS NULL) <> (nilai_teks IS NULL))
            AND ((divalidasi_oleh IS NULL) = (divalidasi_pada IS NULL))
            AND ((diotorisasi_oleh IS NULL) = (diotorisasi_pada IS NULL))
            AND ((status = 'menunggu_validasi') = (divalidasi_oleh IS NULL))
            AND ((status = 'divalidasi') = (dikeluarkan_pada IS NOT NULL))
            AND (diotorisasi_oleh IS NULL OR diotorisasi_oleh <> divalidasi_oleh))");
        // Linimasa episode: hanya hasil final, keyset (dikeluarkan_pada, id).
        DB::statement("CREATE INDEX hasil_linimasa ON lab.hasil (episode_id, dikeluarkan_pada, id) WHERE status = 'divalidasi'");

        Schema::create('lab.hasil_adendum', function (Blueprint $table) {
            $table->comment('Adendum hasil lab tervalidasi (FR-26-06, 7.00.5 butir 3). Hasil asli tidak diubah. Tidak dihapus.');
            $table->uuid('id')->primary();
            $table->foreignUuid('hasil_id')->constrained('lab.hasil')->restrictOnDelete();
            $table->decimal('nilai', 18, 6)->nullable();
            $table->text('nilai_teks')->nullable();
            $table->text('satuan')->nullable();
            $table->foreignUuid('nilai_rujukan_id')->nullable()->constrained('lab.nilai_rujukan')->restrictOnDelete();
            $table->decimal('rujukan_min', 18, 6)->nullable();
            $table->decimal('rujukan_maks', 18, 6)->nullable();
            $table->decimal('kritis_min', 18, 6)->nullable();
            $table->decimal('kritis_maks', 18, 6)->nullable();
            $table->text('penanda')->nullable();
            $table->text('peringatan')->nullable();
            $table->text('alasan');
            $table->kolomStandar();

            $table->index(['hasil_id', 'dibuat_pada']);
        });
        DB::statement("ALTER TABLE lab.hasil_adendum ADD CONSTRAINT hasil_adendum_cek CHECK (
            (penanda IS NULL OR penanda IN ('l', 'h', 'll', 'hh', 'kritis'))
            AND (peringatan IS NULL OR peringatan IN ('nilai_rujukan_tidak_ada'))
            AND ((nilai IS NULL) <> (nilai_teks IS NULL))
            AND length(btrim(alasan)) >= 5 AND dibuat_oleh IS NOT NULL)");

        $sekarang = now();
        // Keputusan pemilik 13 Nov 2026, perlu verifikasi kebijakan RS: tenggat 24 jam, pengingat 4 jam sebelum tenggat,
        // teguran saat tenggat, eskalasi 12 jam sesudah tenggat. Data jenis tugas; RS mengubahnya di layar Jenis Tugas.
        DB::table('inti.jenis_tugas')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'kode' => 'HASIL_LAB_TINDAK_LANJUT', 'nama' => 'Hasil lab untuk ditindaklanjuti',
            'uraian' => 'Hasil lab tervalidasi (atau adendumnya) sampai ke penanggung jawab pasien saat hasil keluar (FR-00-09, 7.00.5 butir 2).',
            'modul' => 'lab', 'objek_jenis' => 'hasil_lab', 'tautan_layar' => '/penunjang/laboratorium/hasil/{hasil_id}', 'tenggat_dari' => 'dibuat',
            'batas_menit' => 1440, 'pengingat_menit' => -240, 'teguran_menit' => 0, 'eskalasi_menit' => 720,
            'kritis' => false, 'aktif' => true, 'dibuat_pada' => $sekarang, 'diubah_pada' => $sekarang,
        ]);

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();
    }

    /** Maju saja di produksi; down untuk lokal. Izin `sahkan`/`setujui` tetap (bisa sudah diberikan ke peran). */
    public function down(): void
    {
        DB::table('inti.jenis_tugas')->where('kode', 'HASIL_LAB_TINDAK_LANJUT')->delete();
        Schema::dropIfExists('lab.hasil_adendum');
        Schema::dropIfExists('lab.hasil');
        Schema::dropIfExists('lab.nilai_rujukan');
        DB::statement('DROP FUNCTION IF EXISTS lab.nilai_rujukan_tolak_tumpang_tindih()');
        Schema::dropIfExists('lab.pemeriksaan');
        DB::statement('DROP INDEX IF EXISTS permintaan.permintaan_item_hasil_aktif');
        DB::statement("UPDATE permintaan.permintaan_item SET status = 'diterima_lab' WHERE status IN ('menunggu_validasi', 'menunggu_otorisasi', 'divalidasi')");
        DB::statement('ALTER TABLE permintaan.permintaan_item DROP CONSTRAINT permintaan_item_cek');
        DB::statement("ALTER TABLE permintaan.permintaan_item ADD CONSTRAINT permintaan_item_cek CHECK (status IN ('dipesan', 'sampel_diambil', 'diterima_lab', 'batal'))");
    }
};
