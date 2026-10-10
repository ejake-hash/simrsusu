-- Role tingkat klaster.
--   simrs_migrator : pemilik skema dan tabel; hanya dipakai untuk `php artisan migrate`.
--   simrs_app      : dipakai aplikasi saat berjalan; DML saja, tanpa DDL.
SELECT format('CREATE ROLE simrs_migrator LOGIN PASSWORD %L', :'migrator_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'simrs_migrator') \gexec

SELECT format('CREATE ROLE simrs_app LOGIN PASSWORD %L', :'app_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'simrs_app') \gexec

ALTER ROLE simrs_app SET search_path = sistem, public;
ALTER ROLE simrs_migrator SET search_path = sistem, public;
