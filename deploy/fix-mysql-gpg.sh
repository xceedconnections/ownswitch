#!/usr/bin/env bash
# One-shot hotfix for EXPKEYSIG B7B3B788A8D3785C / unsigned MySQL APT repo.
# Run on the server, then resume the installer from stage 30:
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/fix-mysql-gpg.sh | sudo bash
#   cd /opt/ownswitch-src && sudo git pull && sudo ./deploy/install.sh --from 30 --yes
#
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

KEYRING=/usr/share/keyrings/mysql.gpg
CODENAME="$(. /etc/os-release; echo "${VERSION_CODENAME:-noble}")"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "==> Downloading MySQL APT signing keys…"
curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 -o "$TMP/2025" || true
curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2023 -o "$TMP/2023" || true
curl -fsSL "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xB7B3B788A8D3785C" -o "$TMP/ks" || true
cat "$TMP"/2025 "$TMP"/2023 "$TMP"/ks 2>/dev/null | grep -q BEGIN || { echo "No key material downloaded"; exit 1; }
cat "$TMP"/2025 "$TMP"/2023 "$TMP"/ks 2>/dev/null >"$TMP/all.asc"

install -d -m 0755 /usr/share/keyrings
gpg --batch --yes --dearmor -o "$KEYRING" "$TMP/all.asc"
chmod 644 "$KEYRING"
cp -f "$KEYRING" /usr/share/keyrings/mysql-apt-config.gpg
cp -f "$KEYRING" /usr/share/keyrings/mysql-8.4.gpg
rm -f /etc/apt/trusted.gpg.d/mysql*.gpg /etc/apt/trusted.gpg.d/mysql*.asc 2>/dev/null || true

echo "==> Rewriting MySQL APT source list…"
# Disable broken lists from mysql-apt-config / prior runs
for f in /etc/apt/sources.list.d/mysql*.list; do
  [[ -e "$f" ]] || continue
  mv -f "$f" "${f}.bak.$(date +%s)" 2>/dev/null || true
done

cat >/etc/apt/sources.list.d/mysql.list <<EOF
# Fixed by Ownswitch deploy/fix-mysql-gpg.sh
deb [signed-by=${KEYRING}] https://repo.mysql.com/apt/ubuntu ${CODENAME} mysql-8.4-lts
deb [signed-by=${KEYRING}] https://repo.mysql.com/apt/ubuntu ${CODENAME} mysql-tools
EOF

echo "==> apt-get update…"
apt-get update -o DPkg::Lock::Timeout=600

echo "==> OK. Resume installer:"
echo "    cd /opt/ownswitch-src && sudo git pull && sudo MYSQL_INSTALL_METHOD=direct ./deploy/install.sh --from 30 --yes"
