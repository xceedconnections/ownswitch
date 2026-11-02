#!/usr/bin/env bash
# Install OpenSIPS UAC registrant support + re-render cfg + sync trunks.
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/enable-trunk-register.sh | sudo bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
cd "$SRC"
git fetch origin main && git reset --hard origin/main

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq || true
apt-get install -y opensips-uac-module || apt-get install -y opensips-module-uac || true

# Import uac_registrant schema if missing
if ! mysql --protocol=socket -uroot -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='opensips' AND table_name='uac_registrant'" | grep -q 1; then
  schema=$(find /usr/share/opensips /usr/share/doc/opensips* -name '*uac*registrant*.sql' 2>/dev/null | head -n1 || true)
  if [[ -n "${schema:-}" ]]; then
    mysql --protocol=socket -uroot opensips <"$schema" || true
  fi
fi

# Re-run OpenSIPS stage to render UAC block + do_routing("1")
bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive || true

# Portal release with new PHP sync code
bash "$SRC/deploy/refresh-ui.sh"

CUR=$(readlink -f /opt/ownswitch/current)
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
  php "$CUR/bin/migrate.php" || php "$CUR/bin/migrate.php" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
  php "$CUR/bin/sync-opensips-trunks.php" || php "$CUR/bin/sync-opensips-trunks.php" || true

echo
echo "Public SIP IP (give this to your Magnus vendor for IP peering):"
hostname -I | awk '{print $1}'
curl -4 -s ifconfig.me 2>/dev/null || curl -4 -s icanhazip.com 2>/dev/null || true
echo
echo "Done. For IP peer trunks: vendor must allowlist the public IP above — they will NOT show Registered."
echo "For Register trunks: edit trunk → Auth mode = Register → save, then check Magnus after ~30s."
