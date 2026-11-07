<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan klinis (asesmen awal perawat RJ, tiket 2.13; CPPT menyusul 2.14), versi perbaikan (FR-26-06), observasi tanda
 * vital berpartisi bulanan (PRD 11.1), dan alergi pasien (FR-26-15). Tanpa DELETE. Isi klinis tidak pernah ke log/audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('klinis.catatan_klinis', function (Blueprint $table) {
            $table->comment('Catatan klinis per kunjungan dan episode (PRD 11.2, 7.26.1). Tiket 2.13: jenis asesmen oleh perawat RJ.');
            $table->uuid('id')->primary();
            $table->uuid('episode_id')->comment('kunjungan.episode.id');
            $table->uuid('kunjungan_id')->comment('kunjungan.kunjungan.id');
            $table->uuid('unit_id')->comment('inti.unit_organisasi.id tempat ditulis (cakupan)');
            $table->uuid('pasien_id')->comment('pasien.pasien.id');
            $table->uuid('penulis_id')->comment('akses.pengguna.id');
            $table->text('profesi');
            $table->text('tatanan');
            $table->text('jenis');
            $table->jsonb('isi')->default('{}')->comment('KLINIS: tidak pernah ke log/audit');
            $table->text('status')->default('draf');
            $table->timestampTz('selesai_pada')->nullable();
            $table->timestampTz('batas_perbaikan_pada')->nullable()->comment('selesai_pada + 2 × 24 jam (FR-26-06)');
            $table->integer('jumlah_versi')->default(0);
            $table->kolomStandar();

            $table->foreign('episode_id')->references('id')->on('kunjungan.episode')->restrictOnDelete();
            $table->foreign('kunjungan_id')->references('id')->on('kunjungan.kunjungan')->restrictOnDelete();
            $table->foreign('pasien_id')->references('id')->on('pasien.pasien')->restrictOnDelete();
            $table->foreign('unit_id')->references('id')->on('inti.unit_organisasi')->restrictOnDelete();
            $table->foreign('penulis_id')->references('id')->on('akses.pengguna')->restrictOnDelete();
            $table->index(['episode_id', 'dibuat_pada', 'id']);
            $table->index(['pasien_id', 'dibuat_pada']);
        });
        DB::statement("ALTER TABLE klinis.catatan_klinis ADD CONSTRAINT catatan_klinis_cek CHECK (profesi IN ('dokter', 'perawat', 'bidan', 'apoteker', 'gizi', 'fisioterapis', 'ppds') AND tatanan IN ('rj', 'igd', 'ri', 'ok', 'icu', 'hd') AND jenis IN ('soap', 'adime', 'asuhan', 'asesmen', 'edukasi', 'catatan_singkat_operasi') AND status IN ('draf', 'selesai', 'menunggu_supervisi', 'menunggu_verifikasi', 'sah') AND ((status = 'draf') = (selesai_pada IS NULL)))");
        // Satu asesmen awal keperawatan per kunjungan (kontrak v0.23.0, 409 asesmen_sudah_ada).
        DB::statement("CREATE UNIQUE INDEX catatan_klinis_asesmen_awal_unik ON klinis.catatan_klinis (kunjungan_id) WHERE jenis = 'asesmen' AND profesi = 'perawat'");

        Schema::create('klinis.catatan_klinis_versi', function (Blueprint $table) {
            $table->comment('Isi catatan sebelum perbaikan sesudah selesai/sah (FR-26-06, Permenkes 24/2022 Ps. 30). Hanya ditambah.');
            $table->uuid('id')->primary();
            $table->foreignUuid('catatan_klinis_id')->constrained('klinis.catatan_klinis')->restrictOnDelete();
            $table->integer('versi');
            $table->jsonb('isi')->comment('KLINIS');
            $table->text('alasan')->comment('KLINIS: tidak pernah ke log/audit');
            $table->foreignUuid('diubah_oleh')->constrained('akses.pengguna')->restrictOnDelete();
            $table->timestampTz('diubah_pada')->useCurrent();

            $table->unique(['catatan_klinis_id', 'versi']);
        });

        DB::unprepared(<<<'SQL'
CREATE TABLE klinis.observasi (
    id                 uuid        NOT NULL,
    kunjungan_id       uuid        NOT NULL REFERENCES kunjungan.kunjungan (id) ON DELETE RESTRICT,
    pasien_id          uuid        NOT NULL REFERENCES pasien.pasien (id) ON DELETE RESTRICT,
    catatan_klinis_id  uuid        REFERENCES klinis.catatan_klinis (id) ON DELETE RESTRICT,
    kode_loinc         text        NOT NULL,
    nilai_angka        numeric,
    nilai_teks         text,
    satuan             text,
    waktu              timestamptz NOT NULL,
    sumber             text        NOT NULL DEFAULT 'manual' CHECK (sumber IN ('manual', 'alat')),
    petugas_id         uuid        REFERENCES akses.pengguna (id) ON DELETE RESTRICT,
    dibatalkan_pada    timestamptz,
    dibuat_pada        timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (id, waktu)
) PARTITION BY RANGE (waktu);
COMMENT ON TABLE klinis.observasi IS 'Observasi berkode LOINC (PRD 11.2), berpartisi per bulan (UTC). Koreksi = baris lama dibatalkan + baris baru, tidak dihapus.';
CREATE INDEX observasi_pasien_idx ON klinis.observasi (pasien_id, kode_loinc, waktu DESC);
CREATE INDEX observasi_catatan_idx ON klinis.observasi (catatan_klinis_id);
CREATE TABLE klinis.observasi_default PARTITION OF klinis.observasi DEFAULT;

CREATE FUNCTION klinis.siapkan_partisi(acuan date, bulan_ke_depan int)
RETURNS TABLE (partisi text, status text) LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, klinis AS $$
DECLARE
    awal timestamptz;
    akhir timestamptz;
BEGIN
    IF bulan_ke_depan < 0 OR bulan_ke_depan > 24 THEN
        RAISE EXCEPTION 'bulan_ke_depan harus 0..24';
    END IF;
    FOR i IN 0..bulan_ke_depan LOOP
        awal := (date_trunc('month', acuan) + make_interval(months => i))::timestamp AT TIME ZONE 'UTC';
        akhir := (date_trunc('month', acuan) + make_interval(months => i + 1))::timestamp AT TIME ZONE 'UTC';
        partisi := 'klinis.observasi_' || to_char(awal AT TIME ZONE 'UTC', 'YYYY_MM');
        IF to_regclass(partisi) IS NOT NULL THEN
            status := 'sudah_ada';
        ELSIF EXISTS (SELECT 1 FROM klinis.observasi_default d WHERE d.waktu >= awal AND d.waktu < akhir) THEN
            status := 'bentrok_default';
            RAISE WARNING 'Partisi % tidak dibuat: ada baris bulan itu di klinis.observasi_default', partisi;
        ELSE
            EXECUTE format('CREATE TABLE %s PARTITION OF klinis.observasi FOR VALUES FROM (%L) TO (%L)', partisi, awal, akhir);
            status := 'dibuat';
        END IF;
        RETURN NEXT;
    END LOOP;
END
$$;
REVOKE ALL ON FUNCTION klinis.siapkan_partisi(date, int) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION klinis.siapkan_partisi(date, int) TO simrs_app;
SQL);
        DB::select('SELECT * FROM klinis.siapkan_partisi(?::date, ?)', [now()->toDateString(), 3]);

        Schema::create('klinis.alergi', function (Blueprint $table) {
            $table->comment('Alergi pasien lintas kunjungan (PRD 11.2, FR-26-15). Tidak dihapus: status batal + alasan.');
            $table->uuid('id')->primary();
            $table->foreignUuid('pasien_id')->constrained('pasien.pasien')->restrictOnDelete();
            $table->text('zat')->comment('KLINIS');
            $table->text('jenis');
            $table->foreignUuid('barang_id')->nullable()->constrained('logistik.barang')->restrictOnDelete();
            $table->text('reaksi')->nullable()->comment('KLINIS');
            $table->text('tingkat')->nullable();
            $table->text('status')->default('aktif');
            $table->text('alasan_batal')->nullable()->comment('KLINIS: tidak pernah ke log/audit');
            $table->uuid('dibatalkan_oleh')->nullable();
            $table->timestampTz('dibatalkan_pada')->nullable();
            $table->kolomStandar();

            $table->index(['pasien_id', 'dibuat_pada', 'id']);
        });
        DB::statement("ALTER TABLE klinis.alergi ADD CONSTRAINT alergi_cek CHECK (jenis IN ('obat', 'makanan', 'lingkungan', 'lainnya') AND (tingkat IS NULL OR tingkat IN ('ringan', 'sedang', 'berat')) AND status IN ('aktif', 'batal') AND ((status = 'batal') = (dibatalkan_pada IS NOT NULL AND alasan_batal IS NOT NULL)))");
        // Alergi aktif yang sama (tanpa beda huruf dan spasi) satu kali per pasien → 409 alergi_sudah_tercatat.
        DB::statement("CREATE UNIQUE INDEX alergi_aktif_unik ON klinis.alergi (pasien_id, lower(regexp_replace(zat, '\\s+', '', 'g'))) WHERE status = 'aktif'");
    }

    public function down(): void
    {
        Schema::dropIfExists('klinis.alergi');
        DB::statement('DROP FUNCTION IF EXISTS klinis.siapkan_partisi(date, int)');
        DB::statement('DROP TABLE IF EXISTS klinis.observasi');
        Schema::dropIfExists('klinis.catatan_klinis_versi');
        Schema::dropIfExists('klinis.catatan_klinis');
    }
};
