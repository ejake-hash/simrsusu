<?php

use App\Modules\Akses\Database\MenuBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Akses darurat (break-glass) dan log akses RM (tiket 2.21; FR-26-12, FR-26-26, UC-33, PRD 11.2). Tanpa DELETE:
 * akses darurat berakhir sendiri menurut berakhir_pada, hasil tinjauan sekali tulis.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE akses.izin DROP CONSTRAINT IF EXISTS izin_jenis_aksi_cek');
        DB::statement("ALTER TABLE akses.izin ADD CONSTRAINT izin_jenis_aksi_cek CHECK (jenis_aksi IN
            ('lihat', 'input', 'perbaiki', 'sahkan', 'verifikasi', 'setujui', 'batal', 'ekspor', 'cetak', 'lihat_rupiah', 'usul', 'darurat', 'tinjau'))");

        Schema::create('akses.akses_darurat', function (Blueprint $table) {
            $table->comment('Akses darurat RM satu pasien oleh satu akun, terbatas waktu, ditinjau pasca-akses (UC-33).');
            $table->uuid('id')->primary();
            $table->foreignUuid('pengguna_id')->constrained('akses.pengguna')->restrictOnDelete();
            $table->foreignUuid('pasien_id')->constrained('pasien.pasien')->restrictOnDelete();
            $table->text('kategori_alasan')->comment('FR-26-26: audit, klaim, hukum, pelayanan_lanjutan');
            $table->text('alasan')->comment('PRIBADI: uraian keadaan darurat; tidak masuk log aplikasi/audit');
            $table->timestampTz('mulai_pada');
            $table->timestampTz('berakhir_pada');
            $table->text('hasil_tinjauan')->nullable();
            $table->text('catatan_tinjauan')->nullable();
            $table->uuid('ditinjau_oleh')->nullable()->comment('akses.pengguna.id');
            $table->timestampTz('ditinjau_pada')->nullable();
            $table->kolomStandar();
            $table->index(['pengguna_id', 'pasien_id', 'berakhir_pada']);
            $table->index(['mulai_pada', 'id']);
        });
        DB::statement("ALTER TABLE akses.akses_darurat ADD CONSTRAINT akses_darurat_kategori_cek CHECK (kategori_alasan IN ('audit', 'klaim', 'hukum', 'pelayanan_lanjutan'))");
        DB::statement('ALTER TABLE akses.akses_darurat ADD CONSTRAINT akses_darurat_masa_cek CHECK (berakhir_pada > mulai_pada)');
        DB::statement("ALTER TABLE akses.akses_darurat ADD CONSTRAINT akses_darurat_tinjauan_cek CHECK (
            (hasil_tinjauan IS NULL AND catatan_tinjauan IS NULL AND ditinjau_oleh IS NULL AND ditinjau_pada IS NULL)
            OR (hasil_tinjauan IN ('wajar', 'tidak_wajar') AND catatan_tinjauan IS NOT NULL AND ditinjau_oleh IS NOT NULL AND ditinjau_pada IS NOT NULL))");

        Schema::table('akses.kebijakan_keamanan', function (Blueprint $table) {
            $table->smallInteger('akses_darurat_menit')->default(240)->comment('Lama akses darurat (UC-33, bawaan 4 jam)');
        });
        DB::statement('ALTER TABLE akses.kebijakan_keamanan ADD CONSTRAINT kebijakan_keamanan_darurat_cek CHECK (akses_darurat_menit BETWEEN 1 AND 1440)');

        DB::unprepared(<<<'SQL'
CREATE TABLE audit.log_akses (
    id                uuid           NOT NULL,
    pengguna_id       uuid           NOT NULL,
    pasien_id         uuid           NOT NULL,
    kunjungan_id      uuid,
    layar             text           NOT NULL,
    aksi              text           NOT NULL CHECK (aksi IN ('lihat', 'cetak', 'ekspor')),
    alasan            text,
    akses_darurat_id  uuid,
    ip                inet,
    terjadi_pada      timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (id, terjadi_pada)
) PARTITION BY RANGE (terjadi_pada);
COMMENT ON TABLE audit.log_akses IS 'Pembukaan RM pasien di luar cakupan (PRD 11.2, FR-26-12), berpartisi per bulan (UTC). Tanpa isi klinis. simrs_app hanya SELECT, INSERT.';
COMMENT ON COLUMN audit.log_akses.alasan IS 'Kategori alasan (FR-26-26), bukan uraian bebas';
COMMENT ON COLUMN audit.log_akses.ip IS 'PRIBADI: alamat IP';
CREATE INDEX log_akses_darurat_idx ON audit.log_akses (akses_darurat_id, terjadi_pada);
CREATE INDEX log_akses_pasien_idx ON audit.log_akses (pasien_id, terjadi_pada);
CREATE TABLE audit.log_akses_default PARTITION OF audit.log_akses DEFAULT;

-- Sama dengan versi 2026_10_11_090000 (batas masukan, DEFAULT bentrok dilewati), kini untuk semua tabel log berpartisi
-- di skema audit yang ada.
CREATE OR REPLACE FUNCTION audit.siapkan_partisi(acuan date, bulan_ke_depan int)
RETURNS TABLE (partisi text, status text)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    i int;
    tabel text;
    awal timestamptz;
    akhir timestamptz;
    bentrok boolean;
BEGIN
    IF acuan IS NULL OR bulan_ke_depan IS NULL THEN
        RAISE EXCEPTION 'acuan dan bulan_ke_depan wajib diisi';
    END IF;
    IF bulan_ke_depan < 0 OR bulan_ke_depan > 24 THEN
        RAISE EXCEPTION 'bulan_ke_depan harus 0..24';
    END IF;
    IF acuan < current_date - interval '1 year' OR acuan > current_date + interval '10 years' THEN
        RAISE EXCEPTION 'acuan harus dalam 1 tahun ke belakang sampai 10 tahun ke depan';
    END IF;

    FOREACH tabel IN ARRAY ARRAY['log_aktivitas', 'log_akses'] LOOP
        CONTINUE WHEN to_regclass('audit.' || tabel) IS NULL;
        FOR i IN 0..bulan_ke_depan LOOP
            awal := (date_trunc('month', acuan) + make_interval(months => i))::timestamp AT TIME ZONE 'UTC';
            akhir := (date_trunc('month', acuan) + make_interval(months => i + 1))::timestamp AT TIME ZONE 'UTC';
            partisi := 'audit.' || tabel || '_' || to_char(awal AT TIME ZONE 'UTC', 'YYYY_MM');

            IF to_regclass(partisi) IS NOT NULL THEN
                status := 'sudah_ada';
            ELSE
                EXECUTE format('SELECT EXISTS (SELECT 1 FROM audit.%I WHERE terjadi_pada >= %L AND terjadi_pada < %L)',
                               tabel || '_default', awal, akhir) INTO bentrok;
                IF bentrok THEN
                    status := 'bentrok_default';
                    RAISE WARNING 'Partisi % tidak dibuat: ada baris bulan itu di audit.%_default', partisi, tabel;
                ELSE
                    EXECUTE format('CREATE TABLE %s PARTITION OF audit.%I FOR VALUES FROM (%L) TO (%L)', partisi, tabel, awal, akhir);
                    status := 'dibuat';
                END IF;
            END IF;

            RETURN NEXT;
        END LOOP;
    END LOOP;
END
$$;
REVOKE ALL ON FUNCTION audit.siapkan_partisi(date, int) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION audit.siapkan_partisi(date, int) TO simrs_app;
SQL);
        DB::select('SELECT * FROM audit.siapkan_partisi(?::date, ?)', [now()->toDateString(), 3]);

        $sekarang = now();
        // Tenggat bawaan 1×24 jam perlu verifikasi RS (UC-33 langkah 3 hanya "dalam tenggat"); diubah lewat Jenis Tugas.
        DB::table('inti.jenis_tugas')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'kode' => 'TINJAUAN_AKSES_DARURAT', 'nama' => 'Tinjauan akses darurat',
            'uraian' => 'Akses darurat RM dibuka; tinjau kewajarannya (UC-33 langkah 3).',
            'modul' => 'akses', 'objek_jenis' => 'akses_darurat', 'tautan_layar' => '/rekam-medis/akses-darurat?akses={objek_id}', 'tenggat_dari' => 'dibuat',
            'batas_menit' => 1440, 'pengingat_menit' => -240, 'teguran_menit' => 0, 'eskalasi_menit' => 1440,
            'kritis' => false, 'aktif' => true, 'dibuat_pada' => $sekarang, 'diubah_pada' => $sekarang,
        ]);

        MenuBawaan::pasang();
    }

    public function down(): void
    {
        DB::table('inti.jenis_tugas')->where('kode', 'TINJAUAN_AKSES_DARURAT')->delete();
        DB::statement('DROP TABLE audit.log_akses');
        DB::statement('ALTER TABLE akses.kebijakan_keamanan DROP CONSTRAINT kebijakan_keamanan_darurat_cek');
        Schema::table('akses.kebijakan_keamanan', fn (Blueprint $table) => $table->dropColumn('akses_darurat_menit'));
        Schema::drop('akses.akses_darurat');
    }
};
