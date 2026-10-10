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

-- Tabel dan sequence yang nanti dibuat simrs_migrator otomatis bisa dipakai simrs_app.
ALTER DEFAULT PRIVILEGES FOR ROLE simrs_migrator
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO simrs_app;
ALTER DEFAULT PRIVILEGES FOR ROLE simrs_migrator
  GRANT USAGE, SELECT ON SEQUENCES TO simrs_app;
