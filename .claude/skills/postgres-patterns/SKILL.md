---
name: postgres-patterns
description: PostgreSQL database patterns for query optimization, schema design, indexing, and security in a Laravel application (no Supabase). Use when designing PostgreSQL schemas, indexes, or RLS policies, writing raw SQL in Laravel, or when a query is too slow.
metadata:
  origin: ECC
---

> ## Aturan proyek SIMRS (mengalahkan isi di bawah)
> Sumber utama: `docs/prd.md` dan `CLAUDE.md` di root repo. Isi asli skill di bawah tetap dibiarkan untuk referensi pola; bila bertentangan, ikuti blok ini.
> - **Kunci utama `id uuid` UUIDv7** (PRD 11.1), bukan `bigint` + kolom `uuid` terpisah. UUIDv7 terurut waktu sehingga aman untuk indeks B-tree; tidak ada `id` numerik yang bocor di URL.
> - Proyek memakai **beberapa role**, bukan satu: `simrs_migrator` (DDL), `simrs_app` (aplikasi), `simrs_analitik`, `simrs_baca`. Hak per skema ada di `docker/postgres/init/sql/02-database.sql` dan PRD 11.1.
> - Nama skema/tabel/kolom bahasa Indonesia; waktu `*_pada timestamptz`; uang `numeric(18,2)`.
> - RLS tidak dipakai saat ini (lihat CLAUDE.md); bila nanti dipakai, contoh `current_setting('app.user_id')::bigint` di bawah harus menjadi `::uuid`.


# PostgreSQL Patterns

Quick reference for PostgreSQL best practices in this Laravel project.

> Project context: plain PostgreSQL accessed by Laravel through ONE application DB role.
> There is no Supabase: `auth.uid()`, `anon` and `service_role` do not exist here.
> Authorization is primarily Laravel Policy/Gate; RLS is optional (see CLAUDE.md).

## When to Activate

- Writing SQL queries or migrations
- Designing database schemas
- Troubleshooting slow queries
- Implementing Row Level Security
- Setting up connection pooling

## Quick Reference

### Index Cheat Sheet

| Query Pattern | Index Type | Example |
|--------------|------------|---------|
| `WHERE col = value` | B-tree (default) | `CREATE INDEX idx ON t (col)` |
| `WHERE col > value` | B-tree | `CREATE INDEX idx ON t (col)` |
| `WHERE a = x AND b > y` | Composite | `CREATE INDEX idx ON t (a, b)` |
| `WHERE jsonb @> '{}'` | GIN | `CREATE INDEX idx ON t USING gin (col)` |
| `WHERE tsv @@ query` | GIN | `CREATE INDEX idx ON t USING gin (col)` |
| Time-series ranges | BRIN | `CREATE INDEX idx ON t USING brin (col)` |

### Data Type Quick Reference

| Use Case | Correct Type | Avoid |
|----------|-------------|-------|
| IDs (primary key) | `uuid` UUIDv7 (PRD 11.1) | `bigint`/`int`, random UUIDv4 as PK |
| IDs in URLs | the `id` itself (UUIDv7) | exposing No. RM or NIK in URLs |
| Strings | `text` | `varchar(255)` |
| Timestamps | `timestamptz` | `timestamp` |
| Money | `numeric(10,2)` | `float` |
| Flags | `boolean` | `varchar`, `int` |

### Common Patterns

**Composite Index Order:**
```sql
-- Equality columns first, then range columns
CREATE INDEX idx ON orders (status, created_at);
-- Works for: WHERE status = 'pending' AND created_at > '2024-01-01'
```

**Covering Index:**
```sql
CREATE INDEX idx ON users (email) INCLUDE (name, created_at);
-- Avoids table lookup for SELECT email, name, created_at
```

**Partial Index:**
```sql
CREATE INDEX idx ON users (email) WHERE deleted_at IS NULL;
-- Smaller index, only includes active users
```

**RLS Policy (only if the team enabled RLS):**
```sql
-- The app sets the user per transaction in Laravel:
--   DB::select("select set_config('app.user_id', ?, true)", [(string) $user->id]);
-- `true` = transaction-local. Never plain SET / set_config(..., false): it leaks to the next
-- request on persistent connections or poolers (PgBouncer).
CREATE POLICY policy ON orders
  USING ((SELECT current_setting('app.user_id', true)::bigint) = user_id);  -- Wrap in SELECT so it is evaluated once
```
The app DB role must not be superuser, must not have BYPASSRLS, and must not own the tables
(owners bypass RLS unless `ALTER TABLE ... FORCE ROW LEVEL SECURITY`).

**UPSERT:**
```sql
INSERT INTO settings (user_id, key, value)
VALUES (123, 'theme', 'dark')
ON CONFLICT (user_id, key)
DO UPDATE SET value = EXCLUDED.value;
```

**Cursor Pagination:**
```sql
SELECT * FROM products WHERE id > $last_id ORDER BY id LIMIT 20;
-- O(1) vs OFFSET which is O(n)
```

**Queue Processing:**
```sql
UPDATE jobs SET status = 'processing'
WHERE id = (
  SELECT id FROM jobs WHERE status = 'pending'
  ORDER BY created_at LIMIT 1
  FOR UPDATE SKIP LOCKED
) RETURNING *;
```

### Anti-Pattern Detection

```sql
-- Find unindexed foreign keys
SELECT conrelid::regclass, a.attname
FROM pg_constraint c
JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey)
WHERE c.contype = 'f'
  AND NOT EXISTS (
    SELECT 1 FROM pg_index i
    WHERE i.indrelid = c.conrelid AND a.attnum = ANY(i.indkey)
  );

-- Find slow queries
SELECT query, mean_exec_time, calls
FROM pg_stat_statements
WHERE mean_exec_time > 100
ORDER BY mean_exec_time DESC;

-- Check table bloat
SELECT relname, n_dead_tup, last_vacuum
FROM pg_stat_user_tables
WHERE n_dead_tup > 1000
ORDER BY n_dead_tup DESC;
```

### Configuration Template

> Cluster-level settings (ALTER SYSTEM) are a DBA/infra decision for the hospital server.
> Propose them; do not apply them from application migrations.

```sql
-- Connection limits (adjust for RAM)
ALTER SYSTEM SET max_connections = 100;
ALTER SYSTEM SET work_mem = '8MB';

-- Timeouts
ALTER SYSTEM SET idle_in_transaction_session_timeout = '30s';
-- statement_timeout: prefer setting it per role, not cluster-wide, so long-running
-- reports/backups (e.g. monthly morbidity reports) are not killed:
--   ALTER ROLE simrs_app SET statement_timeout = '30s';
--   ALTER ROLE simrs_report SET statement_timeout = '10min';

-- Monitoring
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

-- Security defaults
REVOKE ALL ON SCHEMA public FROM public;

SELECT pg_reload_conf();
```

## Related

- Skill: `database-migrations` - safe, zero-downtime migration patterns
- Skill: `healthcare-phi-compliance` - patient data access, audit trail, RLS for patient tables
- Skill: `laravel-patterns` - Eloquent and service-layer patterns

---

*Based on Supabase Agent Skills (credit: Supabase team) (MIT License), via ECC. Adapted for a Laravel/PostgreSQL SIMRS without Supabase.*
