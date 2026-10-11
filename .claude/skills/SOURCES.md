# Asal-usul skill (dibuat oleh install-skills.sh pada 2026-10-10)

| Skill | Sumber | Commit / versi |
|---|---|---|
| laravel-patterns laravel-security laravel-tdd laravel-verification postgres-patterns database-migrations api-design architecture-decision-records healthcare-phi-compliance healthcare-emr-patterns healthcare-eval-harness e2e-testing | github.com/affaan-m/ECC (MIT) | 4eb71d92a39cab44ad40ac9d8a6a5ccb4029d6c2 |
| web-design-guidelines | github.com/vercel-labs/agent-skills + web-interface-guidelines (disimpan lokal) | 063bee94c3f4df8453406c830b0a7df0f2860278 / 434b7f91364665f2f733b310ec54809bf8f37937 |
| playwright-cli | npm @playwright/cli | 0.1.22 |
| satusehat-fhir, bpjs-bridging, regulasi-rme-pdp, koding-icd, rs-pendidikan-akses | dibuat tim (kerangka) | — |

Skill yang sudah diedit tim tidak akan ditimpa kecuali installer dijalankan dengan --force.

## Skill yang sudah disesuaikan untuk SIMRS (10 Okt 2026)

| Skill | Perubahan |
|---|---|
| healthcare-phi-compliance | Ditulis ulang: regulasi Indonesia, contoh PHP/Laravel, Policy/Gate sebagai lapis utama, RLS opsional dengan set_config(..., true), audit_log via REVOKE |
| healthcare-emr-patterns | Ditulis ulang: alur encounter Indonesia (No. RM, penjamin, CPPT/SOAP), validasi DPJP RS Pendidikan, contoh PHP, contoh NEWS2 dikoreksi |
| healthcare-eval-harness | Ditulis ulang: PHPUnit/Pest + `php artisan test`, PostgreSQL di CI, skrip scripts/pass-rate.php |
| postgres-patterns | Diedit: hapus asumsi Supabase (auth.uid), RLS via set_config, PK UUIDv7 di tabel tipe data (tanpa kolom UUID publik terpisah), statement_timeout per role, rujukan agent/skill yang tidak terpasang dihapus |
| web-design-guidelines | Aturan disimpan lokal (guidelines.md), tidak fetch dari internet |

Skill ECC lainnya dipakai apa adanya, **kecuali** enam skill berikut yang diberi blok "Aturan proyek SIMRS (mengalahkan isi di bawah)" tepat setelah frontmatter (10 Okt 2026). Isi asli tidak diubah.

| Skill | Blok "Aturan proyek SIMRS" menetapkan |
|---|---|
| laravel-tdd | PostgreSQL bukan SQLite; `DatabaseTransactions`, larangan `RefreshDatabase`; `composer test` |
| postgres-patterns | PK UUIDv7 (PRD 11.1); beberapa role DB; RLS `::uuid` bila dipakai |
| laravel-patterns | Tanpa `SoftDeletes`; pembatalan via status; migrasi UUIDv7 + kolom `*_pada`; SPA Vue |
| api-design | `/api/v1/` + kata benda Indonesia tanpa jamak; format error PRD 10.3.1 |
| laravel-security | Cookie Sanctum untuk SPA; Argon2id; `akses.pengguna` |
| database-migrations | Hanya migrasi Laravel; `--database=pgsql_migrator`; maju saja di produksi |

## Skill yang dihapus dari proyek

| Skill | Alasan |
|---|---|
| frontend-design | Salinan persis plugin resmi `claude-plugins-official` (commit b8e53f1c05df) yang sudah terpasang global, tidak pernah diedit tim. Dihapus 10 Okt 2026 agar tidak ganda; pakai versi plugin yang ikut diperbarui.
