-- Dijalankan pada setiap database (simrs, simrs_test) sebagai superuser.
SET client_min_messages = warning;
REVOKE ALL ON DATABASE :"DBNAME" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"DBNAME" TO simrs_app;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

-- Ekstensi butuh superuser, jadi dibuat di sini, bukan lewat migrasi.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;

-- `sistem` untuk tabel bawaan Laravel; sisanya skema domain PRD 11.1.
SELECT format('CREATE SCHEMA IF NOT EXISTS %I AUTHORIZATION simrs_migrator', s),
       format('GRANT USAGE ON SCHEMA %I TO simrs_app', s)
FROM unnest(ARRAY[
  'sistem',
  'inti', 'akses', 'pasien', 'kunjungan', 'klinis', 'permintaan',
  'lab', 'radiologi', 'bedah', 'darah', 'pa', 'sterilisasi',
  'farmasi', 'logistik', 'tagihan', 'klaim', 'keuangan', 'sdm', 'mutu',
  'pendidikan', 'riset', 'integrasi', 'notifikasi', 'dokumen', 'ttd',
  'audit', 'analitik'
]) AS s \gexec

-- Hak bawaan global untuk tabel dihapus: hak bawaan per skema di PostgreSQL
-- hanya bisa menambah, jadi hak global DELETE akan menimpa aturan di bawah.
ALTER DEFAULT PRIVILEGES FOR ROLE simrs_migrator
  REVOKE ALL ON TABLES FROM simrs_app;
ALTER DEFAULT PRIVILEGES FOR ROLE simrs_migrator
  GRANT USAGE, SELECT ON SEQUENCES TO simrs_app;

-- Hak per skema untuk simrs_app:
--   sistem : SELECT, INSERT, UPDATE, DELETE (tabel bawaan Laravel: cache, jobs, sessions)
--   audit  : SELECT, INSERT (log tidak boleh diubah atau dihapus)
--   analitik : SELECT (materialized view dasbor; diperbarui oleh simrs_analitik)
--   domain : SELECT, INSERT, UPDATE (tanpa hapus permanen, PRD 11.1; pembatalan lewat status)
-- Untuk tiap skema: cabut dulu semuanya, lalu beri lagi, pada tabel yang sudah ada dan
-- pada tabel yang dibuat nanti. Dengan begitu skrip ini aman dijalankan ulang.
WITH hak(skema, izin) AS (
  SELECT s, CASE s
              WHEN 'sistem' THEN 'SELECT, INSERT, UPDATE, DELETE'
              WHEN 'audit'  THEN 'SELECT, INSERT'
              WHEN 'analitik' THEN 'SELECT'
              ELSE               'SELECT, INSERT, UPDATE'
            END
  FROM unnest(ARRAY[
    'sistem',
    'inti', 'akses', 'pasien', 'kunjungan', 'klinis', 'permintaan',
    'lab', 'radiologi', 'bedah', 'darah', 'pa', 'sterilisasi',
    'farmasi', 'logistik', 'tagihan', 'klaim', 'keuangan', 'sdm', 'mutu',
    'pendidikan', 'riset', 'integrasi', 'notifikasi', 'dokumen', 'ttd',
    'audit', 'analitik'
  ]) AS s
)
SELECT format('REVOKE ALL ON ALL TABLES IN SCHEMA %I FROM simrs_app', skema),
       format('GRANT %s ON ALL TABLES IN SCHEMA %I TO simrs_app', izin, skema),
       format('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA %I TO simrs_app', skema),
       format('ALTER DEFAULT PRIVILEGES FOR ROLE simrs_migrator IN SCHEMA %I REVOKE ALL ON TABLES FROM simrs_app', skema),
       format('ALTER DEFAULT PRIVILEGES FOR ROLE simrs_migrator IN SCHEMA %I GRANT %s ON TABLES TO simrs_app', skema, izin)
FROM hak \gexec


-- Role pendukung (PRD 11.1). Keduanya NOLOGIN sampai modul dasbor dibangun.
--   simrs_analitik : membaca skema domain (kecuali akses, ttd, audit, sistem) agar REFRESH MATERIALIZED VIEW bisa berjalan.
--   simrs_baca     : hanya membaca skema analitik.
GRANT CONNECT ON DATABASE :"DBNAME" TO simrs_analitik, simrs_baca;
GRANT USAGE ON SCHEMA analitik TO simrs_analitik, simrs_baca;
-- Pemilik baru materialized view wajib punya CREATE di skemanya (syarat ALTER ... OWNER TO).
GRANT CREATE ON SCHEMA analitik TO simrs_analitik;
GRANT SELECT ON ALL TABLES IN SCHEMA analitik TO simrs_baca;
ALTER DEFAULT PRIVILEGES FOR ROLE simrs_migrator IN SCHEMA analitik
  GRANT SELECT ON TABLES TO simrs_baca;

SELECT format('GRANT USAGE ON SCHEMA %I TO simrs_analitik', s),
       format('GRANT SELECT ON ALL TABLES IN SCHEMA %I TO simrs_analitik', s),
       format('ALTER DEFAULT PRIVILEGES FOR ROLE simrs_migrator IN SCHEMA %I GRANT SELECT ON TABLES TO simrs_analitik', s)
FROM unnest(ARRAY[
  'inti', 'pasien', 'kunjungan', 'klinis', 'permintaan',
  'lab', 'radiologi', 'bedah', 'darah', 'pa', 'sterilisasi',
  'farmasi', 'logistik', 'tagihan', 'klaim', 'keuangan', 'sdm', 'mutu',
  'pendidikan', 'riset', 'integrasi', 'notifikasi', 'dokumen'
]) AS s \gexec

-- Dikecualikan dari simrs_analitik (PRD 11.1): akses (hash sandi), ttd (PIN, kunci tanda tangan),
-- audit (jejak audit), sistem (sesi, token, antrean). Data dari skema ini untuk dasbor hanya lewat
-- view khusus yang dibuat migrasi dan ditinjau. Dicabut eksplisit, termasuk hak bawaan, supaya
-- database dari versi skrip sebelumnya ikut bersih saat skrip dijalankan ulang.
SELECT format('REVOKE ALL ON ALL TABLES IN SCHEMA %I FROM simrs_analitik', s),
       format('REVOKE ALL ON ALL SEQUENCES IN SCHEMA %I FROM simrs_analitik', s),
       format('ALTER DEFAULT PRIVILEGES FOR ROLE simrs_migrator IN SCHEMA %I REVOKE ALL ON TABLES FROM simrs_analitik', s),
       format('REVOKE USAGE ON SCHEMA %I FROM simrs_analitik', s)
FROM unnest(ARRAY['akses', 'ttd', 'audit', 'sistem']) AS s \gexec
