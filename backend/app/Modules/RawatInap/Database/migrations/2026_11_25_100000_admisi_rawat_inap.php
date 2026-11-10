<?php

use App\Modules\Akses\Application\CacheHakAkses;
use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Admisi rawat inap: penempatan TT, pindah ruang, episode rawat inap (tiket 2.29; FR-08-06, FR-08-04, FR-00-01, FR-00-03,
 * PRD 7.00.2 baris "Rawat inap", PRD 11.2 `rawat_inap` dan `penempatan_tempat_tidur`).
 * - kunjungan.kunjungan jenis `rawat_inap` (kunjungan bangsal) dengan `unit_id` = unit organisasi bangsal TT saat ini
 *   (PRD 11.2 "menyusul: unit_id"); episode jenis `rawat_inap`.
 * - kunjungan.rawat_inap: satu baris per kunjungan rawat inap; `mulai_pada` = awal rawat inap (jam SPRI, atau awal
 *   tanggal rencana untuk SPRI terencana). Kelas hak, naik kelas, deposit menunggu Tahap D.
 * - kunjungan.penempatan_tempat_tidur: satu penempatan aktif per TT dan per pasien (indeks unik parsial; `pasien_id`
 *   disalin dari kunjungan untuk keunikan itu). Tidak dihapus: selesai = jam + alasan (pindah | pulang) + pelaku.
 * - Permintaan kamar aktif unik per pasien (bukan lagi per kunjungan; tinjauan #200). Indeks lama di-drop, data tidak.
 * - Status TT `melewati_rencana_lama_rawat` (FR-08-01; tinjauan #198 butir 2): CHECK saja, disetel rencana pulang (2.40).
 * - Perintah perpindahan berstatus `dilaksanakan`: perintah yang langsung dijalankan tanpa menutup kunjungan (pindah ruang).
 * - Relasi perawatan DPJP rawat inap (lapis 4, PRD 9.2.5 "DPJP rawat inap menyusul"): berlaku sejak penempatan sampai
 *   diakhiri (pulang 2.40), jadi `berlaku_sampai` boleh kosong, hanya untuk peran `dpjp_rawat_inap`.
 * - Sub-menu rawat_inap.penempatan (non-klinis) dan rawat_inap.pindah_ruang (IZIN_KLINIS); sel matriks rawat_inap →
 *   rawat_inap (PRD 7.00.3 "pindah ruang/kelas"). Aksi dasar saja, jadi izin_jenis_aksi_cek tidak berubah.
 */
return new class extends Migration
{
    private const STATUS_TT_LAMA = "'terisi', 'kosong_siap', 'dibersihkan', 'dipesan', 'tidak_dipakai', 'rencana_pulang'";

    private const STATUS_TT = "'terisi', 'kosong_siap', 'dibersihkan', 'dipesan', 'tidak_dipakai', 'rencana_pulang', 'melewati_rencana_lama_rawat'";

    private const SUMBER_TT = "'petugas', 'alokasi', 'alokasi_batal', 'penempatan', 'pindah_ruang', 'pulang'";

    public function up(): void
    {
        // Kunjungan dan episode rawat inap.
        DB::statement('ALTER TABLE kunjungan.kunjungan ADD COLUMN unit_id uuid REFERENCES inti.unit_organisasi (id) ON DELETE RESTRICT');
        DB::statement("COMMENT ON COLUMN kunjungan.kunjungan.unit_id IS 'Unit organisasi pos kunjungan; rawat_inap = unit bangsal TT saat ini (berubah saat pindah ruang). Kosong untuk poli dan IGD'");
        DB::statement('ALTER TABLE kunjungan.kunjungan DROP CONSTRAINT kunjungan_cek');
        DB::statement("ALTER TABLE kunjungan.kunjungan ADD CONSTRAINT kunjungan_cek CHECK (jenis_kunjungan IN ('poliklinik', 'igd', 'rawat_inap') AND cara_bayar IN ('jkn', 'non_jkn') AND status IN ('terdaftar', 'dilayani', 'selesai', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL AND alasan_batal IS NOT NULL)) AND (cara_bayar = 'non_jkn' OR penjamin_id IS NOT NULL))");
        DB::statement('ALTER TABLE kunjungan.kunjungan DROP CONSTRAINT kunjungan_jenis_cek');
        DB::statement("ALTER TABLE kunjungan.kunjungan ADD CONSTRAINT kunjungan_jenis_cek CHECK ((jenis_kunjungan = 'poliklinik' AND jadwal_praktik_id IS NOT NULL AND poli_id IS NOT NULL AND pegawai_id IS NOT NULL AND tiba_pada IS NULL) OR (jenis_kunjungan = 'igd' AND tiba_pada IS NOT NULL) OR (jenis_kunjungan = 'rawat_inap' AND jadwal_praktik_id IS NULL AND poli_id IS NULL AND tiba_pada IS NULL AND unit_id IS NOT NULL))");
        DB::statement('ALTER TABLE kunjungan.episode DROP CONSTRAINT episode_cek');
        DB::statement("ALTER TABLE kunjungan.episode ADD CONSTRAINT episode_cek CHECK (jenis IN ('rawat_jalan_harian', 'igd_lebih_6_jam', 'rawat_inap') AND status IN ('aktif', 'selesai', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL)))");

        Schema::create('kunjungan.rawat_inap', function (Blueprint $table) {
            $table->comment('Kunjungan rawat inap (PRD 11.2 `rawat_inap`, tiket 2.29). Satu baris per kunjungan jenis rawat_inap; kelas hak, naik kelas, deposit di Tahap D.');
            $table->uuid('id')->primary();
            $table->foreignUuid('kunjungan_id')->unique()->comment('Kunjungan jenis rawat_inap')->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->foreignUuid('permintaan_tempat_tidur_id')->unique()->comment('Permintaan kamar yang ditempatkan (SPRI asal)')
                ->constrained('kunjungan.permintaan_tempat_tidur')->restrictOnDelete();
            $table->text('asal')->comment('Pos asal matriks: igd | poliklinik (kamar operasi dan elektif menyusul)');
            $table->foreignUuid('kelas_hak_id')->nullable()->comment('Kelas hak penjamin (Tahap D)')->constrained('inti.kelas_rawat')->restrictOnDelete();
            $table->foreignUuid('kelas_dirawat_id')->comment('Kelas TT saat ini; ikut berubah saat pindah ruang/kelas')->constrained('inti.kelas_rawat')->restrictOnDelete();
            $table->boolean('naik_kelas')->default(false)->comment('Tahap D');
            $table->boolean('wajib_deposit')->default(false)->comment('Tahap D');
            $table->timestampTz('mulai_pada', 6)->comment('Awal rawat inap (7.00.2): jam SPRI, atau awal tanggal rencana untuk SPRI terencana');
            $table->kolomStandar();
        });
        DB::statement("ALTER TABLE kunjungan.rawat_inap ADD CONSTRAINT rawat_inap_cek CHECK (asal IN ('igd', 'poliklinik'))");

        Schema::create('kunjungan.penempatan_tempat_tidur', function (Blueprint $table) {
            $table->comment('Penempatan pasien di TT (PRD 11.2 `penempatan_tempat_tidur`, tiket 2.29). Satu aktif per TT dan per pasien; tidak dihapus. Serah terima klinis, tidak ke log/audit.');
            $table->uuid('id')->primary();
            $table->foreignUuid('tempat_tidur_id')->constrained('inti.tempat_tidur')->restrictOnDelete();
            $table->foreignUuid('kunjungan_id')->comment('Kunjungan jenis rawat_inap')->constrained('kunjungan.kunjungan')->restrictOnDelete();
            $table->foreignUuid('pasien_id')->comment('Salinan kunjungan.pasien_id untuk keunikan satu TT per pasien')->constrained('pasien.pasien')->restrictOnDelete();
            $table->foreignUuid('perpindahan_pos_id')->nullable()->comment('Perintah yang dijalankan penempatan ini: menunggu_kamar (masuk) atau pindah ruang')
                ->constrained('kunjungan.perpindahan_pos')->restrictOnDelete();
            $table->text('serah_terima')->nullable()->comment('KLINIS: serah terima saat pasien tiba di bangsal (bagian data RM-15); tidak pernah di log/audit');
            $table->timestampTz('mulai_pada', 6)->comment('Jam server');
            $table->timestampTz('selesai_pada', 6)->nullable()->comment('Jam server');
            $table->text('alasan_selesai')->nullable()->comment('pindah | pulang');
            $table->foreignUuid('ditempatkan_oleh')->comment('akses.pengguna.id')->constrained('akses.pengguna')->restrictOnDelete();
            $table->uuid('diakhiri_oleh')->nullable()->comment('akses.pengguna.id; kosong = sistem');
            $table->kolomStandar();

            $table->index(['kunjungan_id', 'mulai_pada']);
        });
        DB::statement("ALTER TABLE kunjungan.penempatan_tempat_tidur ADD CONSTRAINT penempatan_tempat_tidur_cek CHECK (
            (alasan_selesai IS NULL OR alasan_selesai IN ('pindah', 'pulang'))
            AND ((selesai_pada IS NULL) = (alasan_selesai IS NULL))
            AND (selesai_pada IS NULL OR selesai_pada >= mulai_pada))");
        DB::statement('CREATE UNIQUE INDEX penempatan_tempat_tidur_tt_aktif_unik ON kunjungan.penempatan_tempat_tidur (tempat_tidur_id) WHERE selesai_pada IS NULL');
        DB::statement('CREATE UNIQUE INDEX penempatan_tempat_tidur_pasien_aktif_unik ON kunjungan.penempatan_tempat_tidur (pasien_id) WHERE selesai_pada IS NULL');

        // Permintaan kamar aktif unik per pasien (2.28 per kunjungan): pasien tidak bisa memesan dua TT.
        DB::statement('ALTER TABLE kunjungan.permintaan_tempat_tidur ADD COLUMN pasien_id uuid REFERENCES pasien.pasien (id) ON DELETE RESTRICT');
        DB::statement('UPDATE kunjungan.permintaan_tempat_tidur p SET pasien_id = k.pasien_id FROM kunjungan.kunjungan k WHERE k.id = p.kunjungan_id');
        DB::statement('ALTER TABLE kunjungan.permintaan_tempat_tidur ALTER COLUMN pasien_id SET NOT NULL');
        DB::statement("COMMENT ON COLUMN kunjungan.permintaan_tempat_tidur.pasien_id IS 'Salinan kunjungan.pasien_id untuk keunikan satu permintaan aktif per pasien'");
        DB::statement('DROP INDEX kunjungan.permintaan_tempat_tidur_aktif_unik');
        DB::statement("CREATE UNIQUE INDEX permintaan_tempat_tidur_pasien_aktif_unik ON kunjungan.permintaan_tempat_tidur (pasien_id) WHERE status IN ('menunggu', 'dialokasikan')");
        DB::statement('CREATE INDEX permintaan_tempat_tidur_kunjungan ON kunjungan.permintaan_tempat_tidur (kunjungan_id)');

        // Status TT `melewati_rencana_lama_rawat` (FR-08-01).
        DB::statement('ALTER TABLE inti.tempat_tidur DROP CONSTRAINT tempat_tidur_status_cek');
        DB::statement('ALTER TABLE inti.tempat_tidur ADD CONSTRAINT tempat_tidur_status_cek CHECK (status_saat_ini IN ('.self::STATUS_TT.'))');
        DB::statement('ALTER TABLE kunjungan.riwayat_status_tempat_tidur DROP CONSTRAINT riwayat_status_tempat_tidur_cek');
        DB::statement('ALTER TABLE kunjungan.riwayat_status_tempat_tidur ADD CONSTRAINT riwayat_status_tempat_tidur_cek CHECK (
            status IN ('.self::STATUS_TT.')
            AND (status_sebelum IS NULL OR status_sebelum IN ('.self::STATUS_TT.'))
            AND sumber_kejadian IN ('.self::SUMBER_TT.'))');

        // Perintah perpindahan yang langsung dijalankan (pindah ruang): kunjungan asal tetap berjalan.
        DB::statement('ALTER TABLE kunjungan.perpindahan_pos DROP CONSTRAINT perpindahan_pos_cek');
        DB::statement("ALTER TABLE kunjungan.perpindahan_pos ADD CONSTRAINT perpindahan_pos_cek CHECK (status IN ('selesai', 'menunggu_kamar', 'menunggu_kamar_operasi', 'dilaksanakan') AND episode_status IN ('aktif', 'selesai') AND length(btrim(alasan)) >= 5)");

        // Relasi perawatan DPJP rawat inap (lapis 4).
        DB::statement('ALTER TABLE akses.relasi_perawatan DROP CONSTRAINT relasi_perawatan_cek');
        DB::statement('ALTER TABLE akses.relasi_perawatan ALTER COLUMN berlaku_sampai DROP NOT NULL');
        DB::statement("ALTER TABLE akses.relasi_perawatan ADD CONSTRAINT relasi_perawatan_cek CHECK (peran IN ('dokter_jadwal', 'dpjp_rawat_inap') AND sumber_jenis IN ('kunjungan') AND status IN ('aktif', 'batal') AND (berlaku_sampai IS NOT NULL OR peran = 'dpjp_rawat_inap') AND (berlaku_sampai IS NULL OR berlaku_sampai > berlaku_dari) AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL)))");
        DB::statement("COMMENT ON COLUMN akses.relasi_perawatan.berlaku_sampai IS 'Kosong hanya untuk dpjp_rawat_inap = berlaku sampai diakhiri (pulang); peran lain wajib berisi'");

        MenuBawaan::pasang();
        app(CacheHakAkses::class)->lupakanSemua();

        $sekarang = now();
        DB::table('kunjungan.aturan_perpindahan')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'pos_asal' => 'rawat_inap', 'pos_tujuan' => 'rawat_inap', 'izin_perintah' => 'rawat_inap.pindah_ruang.input',
            'keterangan' => 'PRD 7.00.3: pindah ruang/kelas', 'status' => 'aktif', 'dibuat_pada' => $sekarang, 'diubah_pada' => $sekarang,
        ]);
    }

    /** Hanya untuk basis data tanpa rawat inap (lokal/uji); produksi maju saja. */
    public function down(): void
    {
        DB::table('kunjungan.aturan_perpindahan')->where('pos_asal', 'rawat_inap')->where('pos_tujuan', 'rawat_inap')->delete();
        DB::statement('ALTER TABLE akses.relasi_perawatan DROP CONSTRAINT relasi_perawatan_cek');
        DB::statement("ALTER TABLE akses.relasi_perawatan ADD CONSTRAINT relasi_perawatan_cek CHECK (peran IN ('dokter_jadwal') AND sumber_jenis IN ('kunjungan') AND status IN ('aktif', 'batal') AND berlaku_sampai > berlaku_dari AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL)))");
        DB::statement('ALTER TABLE akses.relasi_perawatan ALTER COLUMN berlaku_sampai SET NOT NULL');
        DB::statement('ALTER TABLE kunjungan.perpindahan_pos DROP CONSTRAINT perpindahan_pos_cek');
        DB::statement("ALTER TABLE kunjungan.perpindahan_pos ADD CONSTRAINT perpindahan_pos_cek CHECK (status IN ('selesai', 'menunggu_kamar', 'menunggu_kamar_operasi') AND episode_status IN ('aktif', 'selesai') AND length(btrim(alasan)) >= 5)");
        DB::statement('ALTER TABLE kunjungan.riwayat_status_tempat_tidur DROP CONSTRAINT riwayat_status_tempat_tidur_cek');
        DB::statement('ALTER TABLE kunjungan.riwayat_status_tempat_tidur ADD CONSTRAINT riwayat_status_tempat_tidur_cek CHECK (
            status IN ('.self::STATUS_TT_LAMA.')
            AND (status_sebelum IS NULL OR status_sebelum IN ('.self::STATUS_TT_LAMA.'))
            AND sumber_kejadian IN ('.self::SUMBER_TT.'))');
        DB::statement('ALTER TABLE inti.tempat_tidur DROP CONSTRAINT tempat_tidur_status_cek');
        DB::statement('ALTER TABLE inti.tempat_tidur ADD CONSTRAINT tempat_tidur_status_cek CHECK (status_saat_ini IN ('.self::STATUS_TT_LAMA.'))');
        DB::statement('DROP INDEX IF EXISTS kunjungan.permintaan_tempat_tidur_kunjungan');
        DB::statement('DROP INDEX IF EXISTS kunjungan.permintaan_tempat_tidur_pasien_aktif_unik');
        DB::statement("CREATE UNIQUE INDEX permintaan_tempat_tidur_aktif_unik ON kunjungan.permintaan_tempat_tidur (kunjungan_id) WHERE status IN ('menunggu', 'dialokasikan')");
        DB::statement('ALTER TABLE kunjungan.permintaan_tempat_tidur DROP COLUMN pasien_id');
        Schema::dropIfExists('kunjungan.penempatan_tempat_tidur');
        Schema::dropIfExists('kunjungan.rawat_inap');
        DB::statement('ALTER TABLE kunjungan.episode DROP CONSTRAINT episode_cek');
        DB::statement("ALTER TABLE kunjungan.episode ADD CONSTRAINT episode_cek CHECK (jenis IN ('rawat_jalan_harian', 'igd_lebih_6_jam') AND status IN ('aktif', 'selesai', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL)))");
        DB::statement('ALTER TABLE kunjungan.kunjungan DROP CONSTRAINT kunjungan_jenis_cek');
        DB::statement("ALTER TABLE kunjungan.kunjungan ADD CONSTRAINT kunjungan_jenis_cek CHECK ((jenis_kunjungan = 'poliklinik' AND jadwal_praktik_id IS NOT NULL AND poli_id IS NOT NULL AND pegawai_id IS NOT NULL AND tiba_pada IS NULL) OR (jenis_kunjungan = 'igd' AND tiba_pada IS NOT NULL))");
        DB::statement('ALTER TABLE kunjungan.kunjungan DROP CONSTRAINT kunjungan_cek');
        DB::statement("ALTER TABLE kunjungan.kunjungan ADD CONSTRAINT kunjungan_cek CHECK (jenis_kunjungan IN ('poliklinik', 'igd') AND cara_bayar IN ('jkn', 'non_jkn') AND status IN ('terdaftar', 'dilayani', 'selesai', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL AND alasan_batal IS NOT NULL)) AND (cara_bayar = 'non_jkn' OR penjamin_id IS NOT NULL))");
        DB::statement('ALTER TABLE kunjungan.kunjungan DROP COLUMN unit_id');
    }
};
