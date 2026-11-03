#!/usr/bin/env bash
# REGISTER already works (minimal cfg). This restores FULL Ownswitch OpenSIPS:
#   outbound trunks (drouting) + inbound + billing hooks + digest REGISTER kept.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/enable-sip-calling-after-register.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
OWN_ETC=/etc/ownswitch
ENV_FILE="${OWN_ETC}/ownswitch.env"
IC="${OWN_ETC}/install.conf"
cd "$SRC"
git fetch origin main
git reset --hard origin/main

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "$IC" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
PUBLIC_IP="$(curl -4 -s --max-time 5 ifconfig.me || true)"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
LOCAL="$(hostname -I || true)"
if [[ -n "$DNS_IP" && " $LOCAL " == *" $DNS_IP "* ]]; then PUBLIC_IP="$DNS_IP"; fi
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP="${DNS_IP:-$(echo "$LOCAL" | awk '{print $1}')}"
PRIV="$(echo "$LOCAL" | awk '{print $1}')"
export OWNSWITCH_DOMAIN="$DOMAIN" PUBLIC_IP="$PUBLIC_IP" PRIVATE_IP="$PRIV" SIP_PORT=5060

echo "DOMAIN=$DOMAIN PUBLIC_IP=$PUBLIC_IP"

# Unlock cfg written by rebuild (chattr +i)
chattr -i "$CFG" 2>/dev/null || true

echo "======== Schemas: address + drouting + dialog + acc ========"
mapfile -t sql_files < <(find /usr/share/opensips /usr/share/opensips-mysql-module /usr/share/doc \
  -type f \( -name '*-create.sql' -o -name '*create*.sql' \) 2>/dev/null | sort || true)
apply_if_missing() {
  local mod=$1 table=$2 f
  if mysql --protocol=socket -uroot -N -e \
    "SELECT 1 FROM information_schema.tables WHERE table_schema='opensips' AND table_name='${table}'" | grep -q 1; then
    echo "OK table $table"
    return 0
  fi
  f=""
  for cand in "${sql_files[@]}"; do
    base="$(basename "$cand")"
    [[ "$base" == *"${mod}"*create* ]] && { f="$cand"; break; }
  done
  if [[ -n "$f" ]]; then
    echo "Applying $f"
    mysql --protocol=socket -uroot opensips <"$f" || true
  fi
}
apply_if_missing permissions address
apply_if_missing drouting dr_gateways
apply_if_missing dialog dialog
apply_if_missing standard acc
# address version row
mysql --protocol=socket -uroot opensips <<'SQL' || true
CREATE TABLE IF NOT EXISTS address (
  id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  grp SMALLINT(5) UNSIGNED DEFAULT 1 NOT NULL,
  ip CHAR(50) NOT NULL,
  mask TINYINT DEFAULT 32 NOT NULL,
  port SMALLINT(5) UNSIGNED DEFAULT 0 NOT NULL,
  proto CHAR(4) DEFAULT 'any' NOT NULL,
  pattern CHAR(64) DEFAULT NULL,
  context_info CHAR(32) DEFAULT NULL
) ENGINE=InnoDB;
DELETE FROM version WHERE table_name='address';
INSERT INTO version (table_name, table_version) VALUES ('address', 5);
SQL

echo "======== Stage 50: full Ownswitch opensips.cfg ========"
bash "$SRC/deploy/fix-opensips-provision.sh" || true
set +e
bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive
rc=$?
set -e
if [[ "$rc" -ne 0 ]]; then
  echo "FATAL: stage 50 failed"
  tail -n 80 /var/log/ownswitch-install.log || true
  exit 1
fi

# Keep systemd Type=simple -F override
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

grep -q 'www_authorize' "$CFG" || { echo "FATAL: no www_authorize after stage 50"; exit 1; }
grep -q 'do_routing' "$CFG" || { echo "FATAL: no do_routing — outbound will 404"; exit 1; }
grep -q 'sethost(' "$CFG" || grep -q 'rewritehost(' "$CFG" || echo "WARN: no sethost"

opensips -c -f "$CFG"
systemctl stop opensips || true
fuser -k 5060/udp 2>/dev/null || true
fuser -k 5060/tcp 2>/dev/null || true
sleep 1
systemctl start opensips
sleep 2
systemctl is-active opensips || {
  journalctl -u opensips --no-pager -n 60
  exit 1
}

echo "======== Sync SIP users + trunks from portal ========"
bash "$SRC/deploy/refresh-ui.sh" || true
CUR="$(readlink -f /opt/ownswitch/current)"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-trunks.php" || true

# Show route/gateway counts
mysql --protocol=socket -uroot opensips -e \
  "SELECT COUNT(*) AS gateways FROM dr_gateways; SELECT COUNT(*) AS rules FROM dr_rules;" 2>/dev/null || \
  echo "WARN: drouting tables empty — add Trunks + Routes in portal, then re-run sync-opensips-trunks.php"

echo "======== REGISTER still OK? ========"
python3 - "$DOMAIN" "$PUBLIC_IP" <<'PY'
import socket, sys
for host in sys.argv[1:]:
    msg = (
      f"REGISTER sip:{host} SIP/2.0\r\n"
      f"Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKc;rport\r\n"
      f"Max-Forwards: 70\r\n"
      f"From: <sip:c@{host}>;tag=t\r\n"
      f"To: <sip:c@{host}>\r\n"
      f"Call-ID: c-{host}@127.0.0.1\r\n"
      f"CSeq: 1 REGISTER\r\n"
      f"Contact: <sip:c@127.0.0.1:5099>\r\n"
      f"Expires: 60\r\n"
      f"Content-Length: 0\r\n\r\n"
    )
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.settimeout(3)
    s.sendto(msg.encode(), ("127.0.0.1", 5060))
    try:
        print(host, "→", s.recvfrom(8192)[0].decode(errors="replace").splitlines()[0])
    except Exception as e:
        print(host, "→", e)
    s.close()
PY

echo
echo "################################################################"
echo " Full calling stack enabled (REGISTER + drouting)."
echo " Eyebeam: Domain=${PUBLIC_IP} Proxy=BLANK Port=5060 UDP — re-register once."
echo " Outbound needs portal Trunks + a Route group assigned to the customer,"
echo " then: sudo -u ownswitch env OWNSWITCH_ENV_FILE=$ENV_FILE php $CUR/bin/sync-opensips-trunks.php"
echo " Dial a PSTN number (not another offline SIP user)."
echo "################################################################"
