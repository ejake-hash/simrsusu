#!/usr/bin/env bash
# Menyiapkan worktree agar bisa langsung dikerjakan (sesi VS Code atau kartu Korlap).
# Dijalankan dari dalam worktree: scripts/worktree-siapkan.sh [--tanpa-dependensi]
# Idempoten: aman diulang. Folder utama tidak diubah.
#   - .env disalin dari folder utama, lalu port, DB, Valkey, dan cookie sesi dibuat unik per worktree
#   - DB dev simrs_<slug> dan DB tes simrs_test_<slug> dibuat, dimigrasi, dan diisi data contoh
#   - .worktree.env berisi variabel yang perlu di-`source` sebelum composer test / npm run dev / e2e
set -euo pipefail

akar=$(git rev-parse --show-toplevel)
utama=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')
if [ "$akar" = "$utama" ]; then echo "Ini folder utama; skrip hanya untuk worktree."; exit 0; fi
[ -f "$utama/backend/.env" ] || { echo "backend/.env tidak ada di folder utama ($utama)."; exit 1; }

slug=$(basename "$akar" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9\n' '_' | sed 's/^_*//;s/_*$//;s/^simrs_usu_//' | cut -c1-40)
slot_dir="$(git rev-parse --git-common-dir)/simrs-slot"
mkdir -p "$slot_dir"
if [ -f "$slot_dir/$slug" ]; then
  n=$(cat "$slot_dir/$slug")
else
  n=""
  for i in $(seq 1 13); do
    if ! grep -qx "$i" "$slot_dir"/* 2>/dev/null; then n=$i; break; fi
  done
  [ -n "$n" ] || { echo "Slot penuh (13 worktree). Bersihkan worktree lama dulu: scripts/worktree-bersihkan.sh <jalur>"; exit 1; }
  echo "$n" > "$slot_dir/$slug"
fi

port_api=$((8000 + n)); port_vite=$((5173 + n)); redis_db=$((n + 1))
db_dev="simrs_${slug}"; db_tes="simrs_test_${slug}"

# .env backend: salin sekali dari folder utama, lalu timpa nilai per worktree.
env_wt="$akar/backend/.env"
[ -f "$env_wt" ] || cp "$utama/backend/.env" "$env_wt"
setel() { # setel KUNCI NILAI
  if grep -q "^$1=" "$env_wt"; then sed -i.bak "s|^$1=.*|$1=$2|" "$env_wt"; else echo "$1=$2" >> "$env_wt"; fi
}
setel APP_URL "http://127.0.0.1:${port_api}"
setel DB_DATABASE "$db_dev"
setel REDIS_DB "$redis_db"
setel REDIS_CACHE_DB "$redis_db"
setel SESSION_COOKIE "simrs_sesi_${slug}"
setel SANCTUM_STATEFUL_DOMAINS "localhost:${port_vite},127.0.0.1:${port_vite}"
rm -f "$env_wt.bak"

cat > "$akar/.worktree.env" <<VARS
# Dibuat scripts/worktree-siapkan.sh — jangan di-commit. Pakai: source .worktree.env
export WT_SLUG=${slug}
export WT_PORT_API=${port_api}
export WT_PORT_VITE=${port_vite}
export DB_DATABASE_TES=${db_tes}
export SIMRS_API_URL=http://127.0.0.1:${port_api}
export E2E_PORT=${port_vite}
VARS

# Database: lewat container Postgres milik folder utama (proyek compose simrs-usu).
dc() { docker compose -p simrs-usu -f "$utama/docker-compose.yml" "$@"; }
docker info >/dev/null 2>&1 || { echo "Docker belum jalan."; exit 1; }
dc up -d postgres valkey valkey-cache >/dev/null
for db in "$db_dev" "$db_tes"; do
  if [ -z "$(dc exec -T postgres psql -U postgres -tAc "SELECT 1 FROM pg_database WHERE datname='${db}'")" ]; then
    dc exec -T postgres psql -U postgres -q -c "CREATE DATABASE ${db} OWNER simrs_migrator"
    dc exec -T postgres psql -U postgres -q -v ON_ERROR_STOP=1 -v DBNAME="${db}" -d "${db}" \
      < "$akar/docker/postgres/init/sql/02-database.sql"
  fi
done

if [ "${1:-}" != "--tanpa-dependensi" ]; then
  (cd "$akar/backend" && composer install --no-interaction --quiet)
  (cd "$akar/frontend" && npm ci --silent)
fi
(cd "$akar/backend" && php artisan migrate --database=pgsql_migrator --force --quiet && php artisan db:seed --force --quiet)

if command -v graphify >/dev/null 2>&1; then (cd "$akar" && GRAPHIFY_BACKEND=none graphify update . >/dev/null 2>&1 || true); fi

cat <<INFO
Worktree siap: ${slug} (slot ${n})
  Backend : cd backend && php artisan serve --port=${port_api}
  Frontend: source .worktree.env && cd frontend && npm run dev -- --port ${port_vite} --strictPort
  Tes     : source .worktree.env && cd backend && composer test
  E2E     : source .worktree.env && cd frontend && npx playwright test <spec> --project=desktop
  Bersih  : dari folder utama, scripts/worktree-bersihkan.sh ${akar}
INFO
