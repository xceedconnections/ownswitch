#!/usr/bin/env bash
# Run AS ROOT (prompt root@...), from anywhere:
#   bash /opt/ownswitch-src/deploy/PASTE-AS-ROOT-fix-REGISTER.sh
# Or after git pull:
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main && bash deploy/PASTE-AS-ROOT-fix-REGISTER.sh
#
# Do NOT use sudo -u ownswitch. Do NOT run test-sip-register.php first.
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "You must be root (not ownswitch)"; exit 1; }

CFG=/etc/opensips/opensips.cfg
[[ -f "$CFG" ]] || { echo "missing $CFG"; exit 1; }

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' /etc/ownswitch/install.conf 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
PUBLIC_IP="$(curl -4 -s --max-time 5 ifconfig.me || true)"
DNS_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
LOCAL="$(hostname -I 2>/dev/null || true)"
if [[ -n "$DNS_IP" && " $LOCAL " == *" $DNS_IP "* ]]; then PUBLIC_IP="$DNS_IP"; fi
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP="$DNS_IP"
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP="$(echo "$LOCAL" | awk '{print $1}')"
echo "Patching $CFG  DOMAIN=$DOMAIN PUBLIC_IP=$PUBLIC_IP"

install -d /var/backups/ownswitch-config
cp -a "$CFG" "/var/backups/ownswitch-config/opensips.cfg.$(date -u +%Y%m%dT%H%M%SZ)"

python3 - "$CFG" "$DOMAIN" "$PUBLIC_IP" <<'PY'
import re, sys
path, domain, public_ip = sys.argv[1:4]
text = open(path, encoding="utf-8", errors="replace").read()

def set_mp(src, module, param, value):
    pat = re.compile(
        r'(modparam\(\s*"' + re.escape(module) + r'"\s*,\s*"' + re.escape(param) + r'"\s*,\s*)([^)]+)(\))',
        re.I,
    )
    if pat.search(src):
        return pat.sub(r'\g<1>' + value + r'\g<3>', src, count=1)
    if 'loadmodule "auth.so"' not in src:
        src = 'loadmodule "auth.so"\nloadmodule "auth_db.so"\n' + src
    elif 'loadmodule "auth_db.so"' not in src:
        src = src.replace('loadmodule "auth.so"', 'loadmodule "auth.so"\nloadmodule "auth_db.so"', 1)
    m = re.search(r'loadmodule\s+"auth_db\.so"', src)
    if m:
        src = src[: m.end()] + f'\nmodparam("auth_db", "{param}", {value})' + src[m.end() :]
    return src

text = set_mp(text, "auth_db", "calculate_ha1", "1")
text = set_mp(text, "auth_db", "password_column", '"password"')
text = set_mp(text, "auth_db", "use_domain", "0")
if not re.search(r'modparam\(\s*"auth_db"\s*,\s*"db_url"', text):
    m = re.search(r'modparam\(\s*"usrloc"\s*,\s*"db_url"\s*,\s*("[^"]+")\s*\)', text)
    url = m.group(1) if m else '"mysql://opensips:opensips@127.0.0.1/opensips"'
    text = set_mp(text, "auth_db", "db_url", url)

def ensure_alias(src, host):
    if re.search(r'(?m)^alias\s*=\s*"' + re.escape(host) + r'"', src):
        return src
    m = re.search(r'(?m)^auto_aliases\s*=.*$', src) or re.search(r'(?m)^socket\s*=.*$', src)
    line = f'alias="{host}"'
    if m:
        return src[: m.end()] + "\n" + line + src[m.end() :]
    return line + "\n" + src

for h in (domain, public_ip, "127.0.0.1"):
    text = ensure_alias(text, h)

text = re.sub(
    r'if\s*\(\s*is_method\(\s*"REGISTER"\s*\)\s*\)',
    'if (0 && is_method("REGISTER"))',
    text,
)

block = f'''
\tif (is_method("REGISTER")) {{
\t\trewritehost("{public_ip}");
\t\txlog("L_INFO", "OWNREGISTER $si $tU@$td\\n");
\t\tif (!www_authorize("$td", "subscriber")) {{
\t\t\twww_challenge("$td", "auth");
\t\t\texit;
\t\t}}
\t\tif (!save("location")) {{
\t\t\tsl_reply_error();
\t\t\texit;
\t\t}}
\t\texit;
\t}}
'''
m = re.search(r'route\s*\{', text)
if not m:
    sys.exit("no route { in opensips.cfg")
text = text[: m.end()] + block + text[m.end() :]
open(path, "w", encoding="utf-8").write(text)
print("wrote", path)
PY

mysql --protocol=socket -uroot opensips -e \
  "ALTER TABLE subscriber ADD COLUMN password VARCHAR(64) NOT NULL DEFAULT ''" 2>/dev/null || true
mysql --protocol=socket -uroot opensips -e \
  "UPDATE subscriber SET password='Openaccount@12345.' WHERE username='owner'; SELECT username, IF(password='','EMPTY','set') pwd FROM subscriber WHERE username='owner';" \
  || true

echo "opensips -c ..."
if ! opensips -c -f "$CFG"; then
  echo "FATAL: syntax error after patch — restore newest backup in /var/backups/ownswitch-config/"
  exit 1
fi

systemctl stop opensips || true
fuser -k 5060/udp 2>/dev/null || true
fuser -k 5060/tcp 2>/dev/null || true
sleep 1
systemctl start opensips
sleep 2
systemctl is-active opensips

python3 - "$DOMAIN" "$PUBLIC_IP" <<'PY'
import socket, sys
domain, ip = sys.argv[1], sys.argv[2]

def probe(host):
    msg = (
      f"REGISTER sip:{host} SIP/2.0\r\n"
      f"Via: SIP/2.0/UDP 127.0.0.1:5099;branch=z9hG4bKx;rport\r\n"
      f"Max-Forwards: 70\r\n"
      f"From: <sip:x@{host}>;tag=t\r\n"
      f"To: <sip:x@{host}>\r\n"
      f"Call-ID: x-{host}@127.0.0.1\r\n"
      f"CSeq: 1 REGISTER\r\n"
      f"Contact: <sip:x@127.0.0.1:5099>\r\n"
      f"Expires: 60\r\n"
      f"Content-Length: 0\r\n\r\n"
    )
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.settimeout(3)
    s.sendto(msg.encode(), ("127.0.0.1", 5060))
    try:
        return s.recvfrom(8192)[0].decode(errors="replace").splitlines()[0]
    except Exception as e:
        return f"NO_RESPONSE {e}"
    finally:
        s.close()

d, i = probe(domain), probe(ip)
print(f"Domain {domain} → {d}")
print(f"IP     {ip} → {i}")
ok_d = d.startswith("SIP/2.0 401") or d.startswith("SIP/2.0 407")
ok_i = i.startswith("SIP/2.0 401") or i.startswith("SIP/2.0 407")
if not (ok_d and ok_i):
    raise SystemExit("FAIL: still not challenging — run: journalctl -u opensips -n 80 --no-pager")
print("SUCCESS: digest REGISTER is active.")
PY

echo "Softphone: Domain=$PUBLIC_IP Proxy=BLANK User=owner Pass=Openaccount@12345. Port=5060 UDP"
echo "Then: sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/test-sip-register.php owner 'Openaccount@12345.'"
