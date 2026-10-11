-- Role tingkat klaster.
--   simrs_migrator : pemilik skema dan tabel; hanya dipakai untuk `php artisan migrate`.
--   simrs_app      : dipakai aplikasi saat berjalan; DML saja, tanpa DDL.
--   simrs_analitik : pemilik materialized view di skema analitik (REFRESH terjadwal).
--   simrs_baca     : baca skema analitik untuk dasbor/pelaporan.
-- simrs_analitik dan simrs_baca dibuat NOLOGIN dulu (PRD 11.1); LOGIN + kata sandi
-- ditambahkan saat modul dasbor dibangun (fase 4), lewat variabel lingkungan seperti role lain.
SELECT format('CREATE ROLE simrs_migrator LOGIN PASSWORD %L', :'migrator_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'simrs_migrator') \gexec

SELECT format('CREATE ROLE simrs_app LOGIN PASSWORD %L', :'app_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'simrs_app') \gexec

ALTER ROLE simrs_app SET search_path = sistem, public;

SELECT 'CREATE ROLE simrs_analitik NOLOGIN'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'simrs_analitik') \gexec

SELECT 'CREATE ROLE simrs_baca NOLOGIN'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'simrs_baca') \gexec

-- Migrator perlu keanggotaan agar migrasi bisa memindahkan kepemilikan materialized view
-- ke simrs_analitik (ALTER MATERIALIZED VIEW ... OWNER TO simrs_analitik).
SELECT 'GRANT simrs_analitik TO simrs_migrator'
WHERE NOT EXISTS (
  SELECT 1 FROM pg_auth_members m
  JOIN pg_roles r ON r.oid = m.roleid AND r.rolname = 'simrs_analitik'
  JOIN pg_roles u ON u.oid = m.member AND u.rolname = 'simrs_migrator'
) \gexec
ALTER ROLE simrs_migrator SET search_path = sistem, public;

-- Batas waktu per role (PRD 14.1.1 butir 2): query yang melewati batas dibatalkan (SQLSTATE 57014),
-- tidak menahan koneksi. Pekerjaan yang memang lama memakai `SET LOCAL statement_timeout` di transaksinya.
-- Berlaku untuk sesi baru; simrs_analitik baru berlaku saat diberi LOGIN (fase 4).
ALTER ROLE simrs_app SET statement_timeout = '5s';
ALTER ROLE simrs_analitik SET statement_timeout = '5min';
