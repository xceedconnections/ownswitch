#!/usr/bin/env bash
# Eyebeam registered + portal REGISTERED but INVITE → 404 "User not registered"
# = live opensips.cfg still has REGISTER-only dead-end. Force outbound routing.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/force-outbound-calls-now.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
ENV_FILE=/etc/ownswitch/ownswitch.env
cd "$SRC"
git fetch origin main
git reset --hard origin/main

chattr -i "$CFG" 2>/dev/null || true

echo "======== BEFORE ========"
grep -nE 'do_routing|User not registered|gw_attrs_avp|lookup\("location"\)' "$CFG" | head -n 40 || true

echo "======== Sanitize OpenSIPS 3.6 drouting (drop removed gw_attrs_avp) ========"
python3 - "$CFG" <<'PY'
import re, sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()
orig = text
# Removed years ago — causes CRITICAL parse error on 3.x
text = re.sub(
    r'^[ \t]*modparam\(\s*"drouting"\s*,\s*"gw_attrs_avp"\s*,\s*[^)]+\)\s*\n?',
    "",
    text,
    flags=re.M,
)
# Prefer attrs via do_routing 5th argument
text = re.sub(
    r'do_routing\(\s*"1"\s*\)',
    'do_routing("1", , , , $avp(dr_gw_attrs))',
    text,
)
text = re.sub(
    r"do_routing\(\s*'1'\s*\)",
    'do_routing("1", , , , $avp(dr_gw_attrs))',
    text,
)
# Already 5-arg form is fine; avoid double-patching
if text != orig:
    open(path, "w", encoding="utf-8").write(text)
    print("sanitized", path)
else:
    print("no gw_attrs_avp / do_routing() changes needed")
PY

echo "======== Ensure packages / tables ========"
export DEBIAN_FRONTEND=noninteractive
apt-get install -y opensips-mysql-dbschema opensips-mysql-module \
  opensips-auth-modules opensips-nathelper-module 2>/dev/null || true
apt-get install -y opensips-cgrates-module 2>/dev/null || true

bash "$SRC/deploy/patch-minimal-cfg-add-drouting.sh"

# Sanitize again in case patch re-introduced anything
python3 - "$CFG" <<'PY'
import re, sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()
text2 = re.sub(
    r'^[ \t]*modparam\(\s*"drouting"\s*,\s*"gw_attrs_avp"\s*,\s*[^)]+\)\s*\n?',
    "",
    text,
    flags=re.M,
)
if text2 != text:
    open(path, "w", encoding="utf-8").write(text2)
    print("removed leftover gw_attrs_avp")
PY

echo "======== AFTER (must have do_routing, must NOT have User not registered) ========"
grep -nE 'do_routing|User not registered|lookup\("location"\)' "$CFG" | head -n 40 || true
grep -q 'do_routing' "$CFG" || { echo "FATAL: no do_routing"; exit 1; }
grep -q 'User not registered' "$CFG" && { echo "FATAL: dead-end still present"; exit 1; }

install -d /etc/systemd/system/opensips.service.d
cat >/etc/systemd/system/opensips.service.d/ownswitch.conf <<EOF
[Service]
Type=simple
ExecStart=
ExecStart=/usr/sbin/opensips -F -f ${CFG}
Restart=on-failure
RestartSec=2
EOF
systemctl daemon-reload
systemctl stop opensips || true
fuser -k 5060/udp 2>/dev/null || true
fuser -k 5060/tcp 2>/dev/null || true
sleep 1
systemctl start opensips
sleep 2
systemctl is-active opensips || { journalctl -u opensips -n 80 --no-pager; exit 1; }

echo "======== location (registered softphones) ========"
mysql --protocol=socket -uroot opensips -e \
  "SELECT username, domain, LEFT(contact,50) c, received, expires, FROM_UNIXTIME(expires) exp FROM location LIMIT 20;" \
  2>/dev/null || true

echo "======== Sync trunks / SIP users ========"
CUR="$(readlink -f /opt/ownswitch/current)"
bash "$SRC/deploy/refresh-ui.sh" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-trunks.php" || true

echo "======== drouting rows ========"
mysql --protocol=socket -uroot opensips -e \
  "SELECT COUNT(*) AS gateways FROM dr_gateways; SELECT COUNT(*) AS rules FROM dr_rules;
   SELECT id, gwid, address, strip, pri_prefix FROM dr_gateways LIMIT 10;
   SELECT ruleid, groupid, prefix, gwlist, priority FROM dr_rules LIMIT 10;" \
  2>/dev/null || echo "WARN: drouting tables missing"

GW=$(mysql --protocol=socket -uroot -N opensips -e "SELECT COUNT(*) FROM dr_gateways" 2>/dev/null || echo 0)

echo
echo "################################################################"
echo " Softphone: Unregister → Register again in Eyebeam, then dial."
echo " Extension→extension: dial the other SIP username."
echo " PSTN: needs gateways>0 (currently: ${GW})."
if [[ "${GW}" == "0" ]]; then
  echo " ADD portal Trunks + Routes for this customer, then:"
  echo "   sudo -u ownswitch env OWNSWITCH_ENV_FILE=$ENV_FILE php $CUR/bin/sync-opensips-trunks.php"
  echo " Without gateways, PSTN dials return 503 No Route (not User not registered)."
fi
echo " Latency n/a is OPTIONS probe to the phone IP (NAT/firewall); not required for calls."
echo "################################################################"
