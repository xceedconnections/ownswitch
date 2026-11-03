#!/usr/bin/env bash
# MagnusBilling-style softphone REGISTER on OpenSIPS:
#   username + password + Domain=IP OR any FQDN pointed at this server
#   NO Outbound Proxy required
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/fix-softphone-register-magnus.sh | sudo bash
# Optional:  sudo bash .../fix-softphone-register-magnus.sh <sip_user> <sip_pass>
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

TEST_USER="${1:-}"
TEST_PASS="${2:-}"

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_ETC=/etc/ownswitch
ENV_FILE="${OWN_ETC}/ownswitch.env"
IC="${OWN_ETC}/install.conf"
CFG=/etc/opensips/opensips.cfg

cd "$SRC"
git fetch origin main
git reset --hard origin/main

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "$IC" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
DETECT_IP="$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || true)"
LOCAL_IPS="$(hostname -I 2>/dev/null || true)"
PUBLIC_IP="$DETECT_IP"
if [[ -n "$DNS_IP" && " $LOCAL_IPS " == *" $DNS_IP "* ]]; then
  PUBLIC_IP="$DNS_IP"
elif [[ -z "$PUBLIC_IP" && -n "$DNS_IP" ]]; then
  PUBLIC_IP="$DNS_IP"
fi
[[ -n "$PUBLIC_IP" ]] || { echo "FATAL: no PUBLIC_IP"; exit 1; }
PRIV="$(echo "$LOCAL_IPS" | awk '{print $1}')"

echo "DOMAIN=$DOMAIN PUBLIC_IP=$PUBLIC_IP DNS=$DNS_IP LOCAL=$LOCAL_IPS"

grep -vE '^(PRIVATE_IP|PUBLIC_IP|OWNSWITCH_DOMAIN)=' "$IC" > /tmp/ic.$$ 2>/dev/null || true
{ echo "OWNSWITCH_DOMAIN=${DOMAIN}"; echo "PUBLIC_IP=${PUBLIC_IP}"; echo "PRIVATE_IP=${PRIV}"; cat /tmp/ic.$$; } > "$IC"
rm -f /tmp/ic.$$
grep -q '^OPENSIPS_SIP_DOMAIN=' "$ENV_FILE" 2>/dev/null && sed -i "s/^OPENSIPS_SIP_DOMAIN=.*/OPENSIPS_SIP_DOMAIN=${DOMAIN}/" "$ENV_FILE" || echo "OPENSIPS_SIP_DOMAIN=${DOMAIN}" >>"$ENV_FILE"
grep -q '^SIP_PUBLIC_IP=' "$ENV_FILE" 2>/dev/null && sed -i "s/^SIP_PUBLIC_IP=.*/SIP_PUBLIC_IP=${PUBLIC_IP}/" "$ENV_FILE" || echo "SIP_PUBLIC_IP=${PUBLIC_IP}" >>"$ENV_FILE"
grep -q '^OPENSIPS_ENABLED=' "$ENV_FILE" 2>/dev/null && sed -i 's/^OPENSIPS_ENABLED=.*/OPENSIPS_ENABLED=1/' "$ENV_FILE" || echo 'OPENSIPS_ENABLED=1' >>"$ENV_FILE"

export OWNSWITCH_DOMAIN="$DOMAIN" PUBLIC_IP="$PUBLIC_IP" PRIVATE_IP="$PRIV" SIP_PORT="${SIP_PORT:-5060}"

bash "$SRC/deploy/fix-opensips-provision.sh" || true
bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive

# Hard assertions — Magnus-style auth
grep -q 'calculate_ha1", 1' "$CFG" || grep -q 'calculate_ha1",1' "$CFG" || grep -qE 'calculate_ha1",[ ]*1' "$CFG" \
  || { echo "FATAL: calculate_ha1 must be 1 in $CFG"; grep -n calculate_ha1 "$CFG" || true; exit 1; }
grep -q 'password_column", "password"' "$CFG" || grep -qE 'password_column",[ ]*"password"' "$CFG" \
  || { echo "FATAL: password_column must be password"; exit 1; }
grep -q 'www_authorize("", "subscriber")' "$CFG" || grep -q 'www_authorize(""' "$CFG" \
  || { echo "FATAL: www_authorize empty-realm missing"; exit 1; }
grep -q 'use_domain", 0' "$CFG" || true
reg_line="$(grep -n 'is_method("REGISTER")' "$CFG" | head -1 | cut -d: -f1 || true)"
mf_line="$(grep -n 'mf_process_maxfwd_header' "$CFG" | head -1 | cut -d: -f1 || true)"
echo "REGISTER handler line=$reg_line  Max-Forwards line=$mf_line"
if [[ -n "$reg_line" && -n "$mf_line" && "$reg_line" -gt "$mf_line" ]]; then
  echo "FATAL: REGISTER handler is AFTER Max-Forwards"
  exit 1
fi

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
systemctl --no-pager -l status opensips | head -n 20 || true

# Ensure every active portal SIP user has plaintext password in opensips.subscriber
CUR="$(readlink -f /opt/ownswitch/current)"
bash "$SRC/deploy/refresh-ui.sh" || true
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true

# Show password presence (not the value)
mysql --protocol=socket -uroot opensips -e \
  "SELECT username, domain, IF(password IS NULL OR password='', 'EMPTY', 'set') AS pwd, LEFT(ha1,8) ha1 FROM subscriber LIMIT 20;" \
  2>/dev/null || true

probe() {
  local host="$1"
  PROBE_HOST="$host" python3 - <<'PY'
import os, socket
host = os.environ["PROBE_HOST"]
msg = (
  f"REGISTER sip:{host} SIP/2.0\r\n"
  f"Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKforce;rport\r\n"
  f"Max-Forwards: 70\r\n"
  f"From: <sip:forceprobe@{host}>;tag=force\r\n"
  f"To: <sip:forceprobe@{host}>\r\n"
  f"Call-ID: force-{host}@127.0.0.1\r\n"
  f"CSeq: 1 REGISTER\r\n"
  f"Contact: <sip:forceprobe@127.0.0.1:5099>\r\n"
  f"Expires: 60\r\n"
  f"Content-Length: 0\r\n\r\n"
)
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
s.settimeout(3)
s.sendto(msg.encode(), ("127.0.0.1", 5060))
try:
    data, _ = s.recvfrom(8192)
    line = data.decode(errors="replace").splitlines()[0]
except Exception as e:
    line = f"NO_RESPONSE {e}"
s.close()
print(line)
PY
}

echo
echo "== Probes (BOTH must be 401 Unauthorized) =="
D_CODE="$(probe "$DOMAIN")"
I_CODE="$(probe "$PUBLIC_IP")"
echo "Domain $DOMAIN → $D_CODE"
echo "IP     $PUBLIC_IP → $I_CODE"

fail=0
[[ "$D_CODE" == SIP/2.0\ 401* || "$D_CODE" == SIP/2.0\ 407* ]] || fail=1
[[ "$I_CODE" == SIP/2.0\ 401* || "$I_CODE" == SIP/2.0\ 407* ]] || fail=1
if [[ "$fail" -eq 1 ]]; then
  echo "FATAL: OpenSIPS not challenging REGISTER"
  journalctl -u opensips --no-pager -n 80 || true
  exit 1
fi

command -v ufw >/dev/null && { ufw allow 5060/udp || true; ufw allow 5060/tcp || true; ufw allow 30000:40000/udp || true; }

if [[ -n "$TEST_USER" && -n "$TEST_PASS" ]]; then
  runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/test-sip-register.php" "$TEST_USER" "$TEST_PASS" || true
fi

runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/diagnose-sip-portal.php" || true

echo
echo "################################################################"
echo " Magnus-style REGISTER ready"
echo " Softphone (NO Proxy / leave Proxy blank):"
echo "   User     = <sip username>"
echo "   Password = <sip password>"
echo "   Domain   = ${PUBLIC_IP}   OR   ${DOMAIN}   OR any FQDN → ${PUBLIC_IP}"
echo "   Port     = 5060 UDP"
echo " Portal → SIP Users → set password → Save (required once for plaintext sync)"
echo "################################################################"
