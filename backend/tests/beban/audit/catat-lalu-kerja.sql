BEGIN;
INSERT INTO audit.log_aktivitas (id, aksi, subjek_jenis, sesudah, ip, perangkat, terjadi_pada) VALUES (gen_random_uuid(), 'uji.beban.audit', 'uji', '{"uji": true}', '127.0.0.1', 'pgbench', clock_timestamp());
SELECT pg_sleep(0.005);
COMMIT;
