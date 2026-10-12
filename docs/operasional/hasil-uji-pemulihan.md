### Hasil uji pemulihan (12 Oktober 2026, data sintetis)

Lingkungan: PostgreSQL 16.15 (container `postgres:16-alpine` dari `docker-compose.yml`), commit `aa453b8`.

| Langkah | Hasil |
|---|---|
| Database sumber dari skrip yang sama dengan produksi (`02-database.sql`), migrasi sebagai `simrs_migrator`, seed `AkunSintetisSeeder` + `OrganisasiSintetisSeeder`, 25 baris audit | `audit:verifikasi-rantai`: rantai utuh 25 baris |
| `pg_dump -Fc` (sebagai `postgres`) | 1 detik, 408 KB |
| Database kosong `CREATE DATABASE … OWNER simrs_migrator` + hak tingkat database (`REVOKE ALL … FROM PUBLIC`, `GRANT CONNECT, TEMPORARY … TO simrs_app`) lalu `pg_restore --exit-on-error` | < 1 detik, tanpa error |
| Jumlah baris `akses.pengguna`, `akses.peran`, `akses.menu`, `inti.unit_organisasi`, `audit.log_aktivitas`, `sistem.migrations` | sama: 17, 11, 120, 9, 25, 30 |
| `audit:verifikasi-rantai` sesudah pulih | utuh 25 baris; sesudah satu tulis baru 26 baris, urutan 1–26 (trigger rantai berjalan) |
| `migrate:status` | 0 migrasi tertunda |
| Hak `simrs_app` | `INSERT` audit ya, `DELETE` audit tidak, `UPDATE` `akses.pengguna` ya, `CONNECT` ya; `PUBLIC` tanpa `CONNECT` |
| `statement_timeout` `simrs_app` | 5s (pengaturan role tingkat klaster, dari `01-role.sql`) |

Catatan: hak tingkat database (`CONNECT`/`TEMPORARY`) **tidak** ikut `pg_dump` tanpa `--create`, jadi wajib diberikan sebelum
`pg_restore`. Role (`01-role.sql`) dibuat lebih dulu di server pemulihan; sandinya dari vault, bukan dari cadangan.
Uji ini membuktikan cadangan logis harian; PITR (WAL archiving, RPO ≤ 15 menit) diuji terpisah di server staging RS.
