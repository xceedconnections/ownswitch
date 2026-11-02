#!/usr/bin/env bash
# Apply pending Ownswitch DB migrations (creates DID tables etc.).
# On the server as root:
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/fix-migrate.sh | sudo bash
# Or:
#   sudo bash /opt/ownswitch-src/deploy/fix-migrate.sh
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_OPT="${OWNSWITCH_OPT:-/opt/ownswitch}"
OWN_ETC="${OWNSWITCH_ETC:-/etc/ownswitch}"
ENV_FILE="${OWN_ETC}/ownswitch.env"
CUR="$(readlink -f "${OWN_OPT}/current" 2>/dev/null || true)"
MIG="${CUR}/bin/migrate.php"

echo "== Ownswitch fix-migrate =="
echo "SRC=$SRC"
echo "current=$CUR"
echo "env=$ENV_FILE"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "FATAL: $ENV_FILE missing" >&2
  exit 1
fi

# Prefer live release; fall back to source tree
if [[ ! -f "$MIG" ]]; then
  MIG="${SRC}/bin/migrate.php"
fi
if [[ ! -f "$MIG" ]]; then
  echo "FATAL: migrate.php not found under $OWN_OPT/current or $SRC" >&2
  exit 1
fi

# Ensure source has latest migrations then refresh UI so current/ includes 017_dids.sql
if [[ -d "$SRC/.git" ]]; then
  echo "Pulling latest main…"
  git -C "$SRC" fetch origin main
  git -C "$SRC" checkout main
  git -C "$SRC" reset --hard origin/main
  if [[ -x "$SRC/deploy/refresh-ui.sh" ]]; then
    sed -i 's/\r$//' "$SRC/deploy/refresh-ui.sh" 2>/dev/null || true
    bash "$SRC/deploy/refresh-ui.sh"
    CUR="$(readlink -f "${OWN_OPT}/current" 2>/dev/null || true)"
    MIG="${CUR}/bin/migrate.php"
  fi
fi

echo
echo "Running: php $MIG"
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
echo "Done. Hard-refresh browser (Ctrl+Shift+R) and open DIDs again."
echo "Expect status line: 017_dids.sql … applied"
