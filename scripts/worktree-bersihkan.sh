#!/usr/bin/env bash
# Membersihkan worktree tiket yang sudah merge: DB dev/tes, DB Valkey, slot, worktree, branch lokal.
# Pakai dari folder utama: scripts/worktree-bersihkan.sh <jalur-worktree> [--paksa]
# Tanpa --paksa: berhenti bila worktree punya perubahan belum di-commit atau branch belum merge ke origin/main.
set -euo pipefail

wt=${1:?Pakai: scripts/worktree-bersihkan.sh <jalur-worktree> [--paksa]}
paksa=${2:-}
wt=$(cd "$wt" && pwd)
utama=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')
[ "$wt" != "$utama" ] || { echo "Tidak boleh membersihkan folder utama."; exit 1; }

branch=$(git -C "$wt" rev-parse --abbrev-ref HEAD)
if [ "$paksa" != "--paksa" ]; then
  [ -z "$(git -C "$wt" status --porcelain)" ] || { echo "Ada perubahan belum di-commit di $wt. Berhenti."; exit 1; }
  # PR di-squash, jadi status merge dicek dari GitHub, bukan dari riwayat git.
  if ! command -v gh >/dev/null 2>&1 || [ -z "$(gh pr list --head "$branch" --state merged --json number -q '.[].number')" ]; then
    echo "PR untuk $branch belum merge (atau gh tidak ada). Pakai --paksa bila yakin."; exit 1
  fi
fi

slug=$(basename "$wt" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9\n' '_' | sed 's/^_*//;s/_*$//;s/^simrs_usu_//' | cut -c1-40)
slot_file="$(git rev-parse --git-common-dir)/simrs-slot/$slug"
dc() { docker compose -p simrs-usu -f "$utama/docker-compose.yml" "$@"; }

if docker info >/dev/null 2>&1; then
  for db in "simrs_${slug}" "simrs_test_${slug}" "simrs_e2e_${slug}"; do
    dc exec -T postgres psql -U postgres -q -c "DROP DATABASE IF EXISTS ${db} WITH (FORCE)" || true
  done
  if [ -f "$slot_file" ]; then
    n=$(cat "$slot_file"); redis_db=$((n + 1))
    dc exec -T valkey valkey-cli -n "$redis_db" flushdb >/dev/null || true
    dc exec -T valkey-cache valkey-cli -n "$redis_db" flushdb >/dev/null || true
  fi
else
  echo "Docker tidak jalan: DB dan Valkey tidak dibersihkan."
fi
rm -f "$slot_file"

git worktree remove ${paksa:+--force} "$wt"
git branch -D "$branch" 2>/dev/null || true
echo "Bersih: $wt ($branch). Branch remote dihapus oleh 'gh pr merge --delete-branch'."
echo "Sisa worktree:"; git worktree list
