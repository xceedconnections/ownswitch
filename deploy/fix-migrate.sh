#!/usr/bin/env bash
# Apply pending Ownswitch DB migrations (creates DID tables etc.).
# On the server as root:
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/fix-migrate.sh | sudo bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_OPT="${OWNSWITCH_OPT:-/opt/ownswitch}"
OWN_ETC="${OWNSWITCH_ETC:-/etc/ownswitch}"
ENV_FILE="${OWN_ETC}/ownswitch.env"

echo "== Ownswitch fix-migrate =="

if [[ ! -f "$ENV_FILE" ]]; then
  echo "FATAL: $ENV_FILE missing" >&2
  exit 1
fi

if [[ -d "$SRC/.git" ]]; then
  echo "Pulling latest main…"
  git -C "$SRC" fetch origin main
  git -C "$SRC" checkout main
  git -C "$SRC" reset --hard origin/main
  HEAD="$(git -C "$SRC" rev-parse --short HEAD)"
  echo "Source at $HEAD"
  # Prove qualify fix is present (must NOT contain unquoted ADD COLUMN qualify)
  if grep -n "ADD COLUMN qualify " "$SRC/database/migrations/015_magnus_sip_params.sql" 2>/dev/null; then
    echo "FATAL: old 015 still has unquoted qualify — git pull failed" >&2
    exit 1
  fi
  if [[ -x "$SRC/deploy/refresh-ui.sh" ]]; then
    sed -i 's/\r$//' "$SRC/deploy/refresh-ui.sh" 2>/dev/null || true
    bash "$SRC/deploy/refresh-ui.sh"
  fi
fi

CUR="$(readlink -f "${OWN_OPT}/current" 2>/dev/null || true)"
MIG="${CUR}/bin/migrate.php"
[[ -f "$MIG" ]] || MIG="${SRC}/bin/migrate.php"
[[ -f "$MIG" ]] || { echo "FATAL: migrate.php not found"; exit 1; }

echo
echo "Status before:"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG" --status 2>/dev/null \
  || env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG" --status || true

echo
echo "Applying migrations…"
if runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG"; then
  echo "Migrations OK (ownswitch user)"
elif env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG"; then
  echo "Migrations OK (root)"
else
  echo "FATAL: migrate.php failed — paste the error above" >&2
  exit 1
fi

echo
echo "Status after:"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG" --status 2>/dev/null \
  || env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$MIG" --status || true

echo
echo "Done. Hard-refresh browser (Ctrl+Shift+R)."
echo "Expect: 015…applied, 017_dids.sql applied, 018_fix_qualify…applied"
