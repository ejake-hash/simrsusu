<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * audit.log_aktivitas menjadi tabel berpartisi RANGE per bulan (UTC) dengan hash berantai
 * (PRD 11.2, 7.57.2 butir d, 14.2). Lihat docs/adr/0003-rantai-hash-audit.md.
 *
 * Tabel lama dibuang dan dibuat ulang karena masih kosong di semua lingkungan; migrasi menolak
 * berjalan bila ternyata sudah ada isinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('audit.log_aktivitas')->exists()) {
            throw new RuntimeException('audit.log_aktivitas tidak kosong; migrasi ini hanya untuk tabel kosong.');
        }

        DB::statement('DROP TABLE audit.log_aktivitas');

        DB::unprepared(<<<'SQL'
CREATE SEQUENCE audit.log_aktivitas_urutan_seq AS bigint;

CREATE TABLE audit.log_aktivitas (
    id            uuid        NOT NULL,
    urutan        bigint      NOT NULL,
    pengguna_id   uuid,
    aksi          text        NOT NULL,
    subjek_jenis  text,
    subjek_id     uuid,
    sebelum       jsonb,
    sesudah       jsonb,
    ip            inet,
    perangkat     text,
    terjadi_pada  timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
    hash_rantai   char(64)    NOT NULL,
    PRIMARY KEY (id, terjadi_pada)
) PARTITION BY RANGE (terjadi_pada);

COMMENT ON TABLE audit.log_aktivitas IS 'Jejak aktivitas pengguna, berpartisi per bulan (UTC), hash berantai. simrs_app hanya SELECT, INSERT.';
COMMENT ON COLUMN audit.log_aktivitas.urutan IS 'Urutan rantai; diisi trigger di bawah kunci advisory. Boleh berlubang, tidak boleh mundur.';
COMMENT ON COLUMN audit.log_aktivitas.pengguna_id IS 'akses.pengguna.id; kosong bila pelaku tidak dikenal';
COMMENT ON COLUMN audit.log_aktivitas.aksi IS 'Kode aksi, mis. autentikasi.masuk';
COMMENT ON COLUMN audit.log_aktivitas.sebelum IS 'Keadaan sebelum; tanpa sandi, token, atau data pasien';
COMMENT ON COLUMN audit.log_aktivitas.sesudah IS 'Keadaan sesudah atau rincian; tanpa sandi, token, atau data pasien';
COMMENT ON COLUMN audit.log_aktivitas.ip IS 'PRIBADI: alamat IP';
COMMENT ON COLUMN audit.log_aktivitas.perangkat IS 'User-Agent, dipotong 255 karakter';
COMMENT ON COLUMN audit.log_aktivitas.hash_rantai IS 'SHA-256 hex dari hash_rantai baris sebelumnya || audit.kanonik_log(baris ini)';

CREATE INDEX log_aktivitas_urutan_idx ON audit.log_aktivitas (urutan);
CREATE INDEX log_aktivitas_pengguna_idx ON audit.log_aktivitas (pengguna_id, terjadi_pada);
CREATE INDEX log_aktivitas_aksi_idx ON audit.log_aktivitas (aksi, terjadi_pada);

-- Jaring pengaman bila partisi bulan berjalan belum dibuat oleh perintah terjadwal.
CREATE TABLE audit.log_aktivitas_default PARTITION OF audit.log_aktivitas DEFAULT;

-- Serialisasi kanonik satu baris. Satu-satunya definisi; dipakai trigger dan verifikasi.
-- jsonb::text deterministik (kunci objek terurut), waktu selalu UTC dengan mikrodetik.
CREATE FUNCTION audit.kanonik_log(l audit.log_aktivitas) RETURNS text
LANGUAGE sql STABLE
SET search_path = pg_catalog, pg_temp
AS $$
    SELECT jsonb_build_array(
        l.id::text,
        l.urutan,
        to_char(l.terjadi_pada AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        l.pengguna_id::text,
        l.aksi,
        l.subjek_jenis,
        l.subjek_id::text,
        l.sebelum,
        l.sesudah,
        host(l.ip),
        l.perangkat
    )::text
$$;

CREATE FUNCTION audit.hash_rantai(sebelumnya text, l audit.log_aktivitas) RETURNS text
LANGUAGE sql STABLE
SET search_path = pg_catalog, pg_temp
AS $$
    SELECT encode(sha256(convert_to(sebelumnya || audit.kanonik_log(l), 'UTF8')), 'hex')
$$;

-- Menyambung setiap baris baru ke rantai. Kunci advisory tingkat transaksi menjamin hanya satu
-- penulis yang membaca "hash terakhir" dan menambah baris pada satu waktu; kunci dilepas saat
-- transaksi pemanggil selesai.
CREATE FUNCTION audit.sambung_rantai() RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    sebelumnya text;
BEGIN
    -- Di REPEATABLE READ/SERIALIZABLE, snapshot diambil sebelum kunci didapat sehingga baris
    -- penulis sebelumnya tidak terlihat dan rantai bercabang.
    IF current_setting('transaction_isolation') <> 'read committed' THEN
        RAISE EXCEPTION 'audit.log_aktivitas hanya boleh ditulis dalam transaksi READ COMMITTED';
    END IF;

    PERFORM pg_advisory_xact_lock(hashtextextended('audit.log_aktivitas.rantai', 0));

    NEW.urutan := nextval('audit.log_aktivitas_urutan_seq');
    SELECT l.hash_rantai INTO sebelumnya
      FROM audit.log_aktivitas l
     ORDER BY l.urutan DESC
     LIMIT 1;
    NEW.hash_rantai := audit.hash_rantai(coalesce(sebelumnya, repeat('0', 64)), NEW);

    RETURN NEW;
END
$$;

CREATE TRIGGER log_aktivitas_sambung_rantai
    BEFORE INSERT ON audit.log_aktivitas
    FOR EACH ROW EXECUTE FUNCTION audit.sambung_rantai();

-- Membuat partisi bulan acuan + n bulan ke depan (batas bulan UTC). SECURITY DEFINER milik
-- simrs_migrator supaya perintah terjadwal cukup memakai simrs_app (tanpa kredensial DDL).
-- Bulan yang sudah punya baris di partisi DEFAULT dilewati dan dilaporkan, karena partisi baru
-- tidak bisa dibuat selama barisnya ada di DEFAULT (lihat ADR 0003).
CREATE FUNCTION audit.siapkan_partisi(acuan date, bulan_ke_depan int)
RETURNS TABLE (partisi text, status text)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    i int;
    awal timestamptz;
    akhir timestamptz;
BEGIN
    -- Fungsi ini menjalankan DDL atas nama simrs_migrator: masukan dibatasi agar simrs_app tidak
    -- bisa membuat partisi sembarang (ribuan partisi memperlambat setiap kueri ke tabel induk).
    IF acuan IS NULL OR bulan_ke_depan IS NULL THEN
        RAISE EXCEPTION 'acuan dan bulan_ke_depan wajib diisi';
    END IF;
    IF bulan_ke_depan < 0 OR bulan_ke_depan > 24 THEN
        RAISE EXCEPTION 'bulan_ke_depan harus 0..24';
    END IF;
    IF acuan < current_date - interval '1 year' OR acuan > current_date + interval '10 years' THEN
        RAISE EXCEPTION 'acuan harus dalam 1 tahun ke belakang sampai 10 tahun ke depan';
    END IF;

    FOR i IN 0..bulan_ke_depan LOOP
        awal := (date_trunc('month', acuan) + make_interval(months => i))::timestamp AT TIME ZONE 'UTC';
        akhir := (date_trunc('month', acuan) + make_interval(months => i + 1))::timestamp AT TIME ZONE 'UTC';
        partisi := 'audit.log_aktivitas_' || to_char(awal AT TIME ZONE 'UTC', 'YYYY_MM');

        IF to_regclass(partisi) IS NOT NULL THEN
            status := 'sudah_ada';
        ELSIF EXISTS (SELECT 1 FROM audit.log_aktivitas_default d
                       WHERE d.terjadi_pada >= awal AND d.terjadi_pada < akhir) THEN
            status := 'bentrok_default';
            RAISE WARNING 'Partisi % tidak dibuat: ada baris bulan itu di audit.log_aktivitas_default', partisi;
        ELSE
            EXECUTE format('CREATE TABLE %s PARTITION OF audit.log_aktivitas FOR VALUES FROM (%L) TO (%L)',
                           partisi, awal, akhir);
            status := 'dibuat';
        END IF;

        RETURN NEXT;
    END LOOP;
END
$$;

REVOKE ALL ON FUNCTION audit.siapkan_partisi(date, int) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION audit.siapkan_partisi(date, int) TO simrs_app;
SQL);

        DB::select('SELECT * FROM audit.siapkan_partisi(?::date, ?)', [now()->toDateString(), 3]);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
-- Fungsi yang memakai tipe baris tabel harus dibuang sebelum tabelnya.
DROP FUNCTION audit.siapkan_partisi(date, int);
DROP FUNCTION audit.hash_rantai(text, audit.log_aktivitas);
DROP FUNCTION audit.kanonik_log(audit.log_aktivitas);
DROP TABLE audit.log_aktivitas;
DROP FUNCTION audit.sambung_rantai();
DROP SEQUENCE audit.log_aktivitas_urutan_seq;

CREATE TABLE audit.log_aktivitas (
    id            uuid PRIMARY KEY,
    pengguna_id   uuid,
    aksi          text NOT NULL,
    subjek_jenis  text,
    subjek_id     uuid,
    sebelum       jsonb,
    sesudah       jsonb,
    ip            inet,
    perangkat     text,
    terjadi_pada  timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX audit_log_aktivitas_pengguna_id_terjadi_pada_index ON audit.log_aktivitas (pengguna_id, terjadi_pada);
CREATE INDEX audit_log_aktivitas_aksi_terjadi_pada_index ON audit.log_aktivitas (aksi, terjadi_pada);
SQL);
    }
};
