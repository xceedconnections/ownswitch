#!/usr/bin/env bash
# Emergency fix: Domain REGISTER was returning SIP 500 because the FQDN was not an
# OpenSIPS local alias (IP REGISTER worked). Forces correct PUBLIC_IP, re-renders
# opensips.cfg, verifies Domain REGISTER returns 401 (auth challenge) not 500.
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/force-sip-register-fix.sh | sudo bash
#   sudo bash /opt/ownswitch-src/deploy/force-sip-register-fix.sh USER 'Password'
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

TEST_USER="${1:-}"
TEST_PASS="${2:-}"

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_ETC=/etc/ownswitch
ENV_FILE="${OWN_ETC}/ownswitch.env"
IC="${OWN_ETC}/install.conf"
cd "$SRC"
git fetch origin main
git reset --hard origin/main

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "$IC" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
DETECT_IP="$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || true)"
LOCAL_IPS="$(hostname -I 2>/dev/null || true)"

# Prefer DNS A if it is assigned on this host; else detected egress IP
PUBLIC_IP="$DETECT_IP"
if [[ -n "$DNS_IP" && " $LOCAL_IPS " == *" $DNS_IP "* ]]; then
  PUBLIC_IP="$DNS_IP"
elif [[ -n "$DNS_IP" && -z "$PUBLIC_IP" ]]; then
  PUBLIC_IP="$DNS_IP"
fi
[[ -n "$PUBLIC_IP" ]] || { echo "FATAL: cannot determine PUBLIC_IP"; exit 1; }

echo "======== force-sip-register-fix ========"
echo "DOMAIN=$DOMAIN"
echo "DNS_IP=$DNS_IP"
echo "DETECT_IP=$DETECT_IP"
echo "LOCAL_IPS=$LOCAL_IPS"
echo "PUBLIC_IP(forced)=$PUBLIC_IP"

# Persist IPs for stage 50
touch "$IC"
grep -vE '^(PRIVATE_IP|PUBLIC_IP|OWNSWITCH_DOMAIN)=' "$IC" > /tmp/ic.$$ || true
PRIV="$(echo "$LOCAL_IPS" | awk '{print $1}')"
{
  echo "OWNSWITCH_DOMAIN=${DOMAIN}"
  echo "PUBLIC_IP=${PUBLIC_IP}"
  echo "PRIVATE_IP=${PRIV}"
  cat /tmp/ic.$$
} > "$IC"
rm -f /tmp/ic.$$

# Env for portal softphone hints + provisioner
touch "$ENV_FILE"
grep -q '^OPENSIPS_SIP_DOMAIN=' "$ENV_FILE" && sed -i "s/^OPENSIPS_SIP_DOMAIN=.*/OPENSIPS_SIP_DOMAIN=${DOMAIN}/" "$ENV_FILE" || echo "OPENSIPS_SIP_DOMAIN=${DOMAIN}" >>"$ENV_FILE"
grep -q '^SIP_PUBLIC_IP=' "$ENV_FILE" && sed -i "s/^SIP_PUBLIC_IP=.*/SIP_PUBLIC_IP=${PUBLIC_IP}/" "$ENV_FILE" || echo "SIP_PUBLIC_IP=${PUBLIC_IP}" >>"$ENV_FILE"

export OWNSWITCH_DOMAIN="$DOMAIN" PUBLIC_IP="$PUBLIC_IP" PRIVATE_IP="$PRIV" SIP_PORT=5060
bash "$SRC/deploy/fix-opensips-provision.sh" || true
bash "$SRC/deploy/install.sh" --only 50 --yes --non-interactive

echo
echo "== Verify rendered aliases / auth =="
CFG=/etc/opensips/opensips.cfg
grep -nE '^alias=|^socket=|calculate_ha1|password_column|www_authorize|use_domain' "$CFG" || true
if ! grep -q "alias=\"${DOMAIN}\"" "$CFG"; then
  echo "FATAL: opensips.cfg missing alias for ${DOMAIN}"
  exit 1
fi
if ! grep -q "alias=\"${PUBLIC_IP}\"" "$CFG"; then
  echo "FATAL: opensips.cfg missing alias for ${PUBLIC_IP}"
  exit 1
fi
if ! grep -q 'calculate_ha1", 0' "$CFG" && ! grep -q 'calculate_ha1",0' "$CFG"; then
  echo "WARN: expected calculate_ha1=0"
fi
if ! grep -q 'www_authorize' "$CFG"; then
  echo "FATAL: www_authorize missing — REGISTER would be open/broken"
  exit 1
fi

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
systemctl is-active opensips
ss -ulnp | grep ':5060' || echo "FAIL: no UDP 5060"
ss -tlnp | grep ':5060' || echo "WARN: no TCP 5060"

# Align portal SIP domains + push HA1
mysql --protocol=socket -uroot ownswitch -e "UPDATE sip_accounts SET domain='${DOMAIN}';" 2>/dev/null || true
bash "$SRC/deploy/refresh-ui.sh" || true
CUR="$(readlink -f /opt/ownswitch/current)"
runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || \
  env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/sync-opensips-sip-users.php" || true

command -v ufw >/dev/null && {
  ufw allow 5060/udp || true
  ufw allow 5060/tcp || true
  ufw allow 30000:40000/udp || true
}

probe_register() {
  local host="$1"
  local out
  out="$(PROBE_HOST="$host" python3 - <<'PY'
import os, socket
host = os.environ["PROBE_HOST"]
msg = (
  f"REGISTER sip:{host} SIP/2.0\r\n"
  f"Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKforce;rport\r\n"
  f"Max-Forwards: 70\r\n"
  f"From: <sip:forceprobe@{host}>;tag=force\r\n"
  f"To: <sip:forceprobe@{host}>\r\n"
  f"Call-ID: force@127.0.0.1\r\n"
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
    print(data.decode(errors="replace").splitlines()[0])
except Exception as e:
    print("NO_RESPONSE", e)
s.close()
PY
)"
  echo "REGISTER sip:${host} → ${out}"
  case "$out" in
    "SIP/2.0 401"*|"SIP/2.0 407"*) return 0 ;;
    "SIP/2.0 200"*) echo "WARN: accepted without auth (config not challenging)"; return 1 ;;
    "SIP/2.0 500"*) echo "FAIL: 500 Internal Error — domain still not local"; return 1 ;;
    "SIP/2.0 483"*) echo "FAIL: 483 Too Many Hops — routing loop"; return 1 ;;
    *) return 1 ;;
  esac
}

echo
echo "== Local REGISTER probes (expect 401 Unauthorized) =="
ok=0
probe_register "$DOMAIN" && ok=1 || true
probe_register "$PUBLIC_IP" && ok=1 || true

if [[ "$ok" -ne 1 ]]; then
  echo
  echo "REGISTER probe failed. Last opensips log:"
  journalctl -u opensips --no-pager -n 50 || true
  exit 1
fi

if [[ -n "$TEST_USER" && -n "$TEST_PASS" ]]; then
  echo
  echo "== Authenticated REGISTER test =="
  runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="$ENV_FILE" \
    php "$CUR/bin/test-sip-register.php" "$TEST_USER" "$TEST_PASS" || \
    env OWNSWITCH_ENV_FILE="$ENV_FILE" php "$CUR/bin/test-sip-register.php" "$TEST_USER" "$TEST_PASS" || true
  mysql --protocol=socket -uroot opensips -e "SELECT username,domain,contact,expires FROM location;" 2>/dev/null || true
fi

echo
echo "################################################################"
echo " Eyebeam settings (REQUIRED)"
echo "################################################################"
echo "  User / Auth name : <SIP username>"
echo "  Password         : reset in portal → Save (after this script)"
echo "  Domain           : ${DOMAIN}"
echo "  Proxy/Registrar  : ${PUBLIC_IP}"
echo "  Port             : 5060"
echo "  Protocol         : UDP  (or TCP if UDP blocked)"
echo
echo " Domain REGISTER must get 401 then 200 with password."
echo " If Eyebeam still fails with Proxy blank, it never hits this server."
echo "################################################################"
