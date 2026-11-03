#!/usr/bin/env bash
# Asterisk 416 Unsupported URI scheme — From rewrite omitted "sip:" prefix.
# Was: From: <12138007447@host>   Need: From: <sip:12138007447@host>
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-from-sip-scheme.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
cd "$SRC"
git fetch origin main
git reset --hard origin/main
chattr -i "$CFG" 2>/dev/null || true

# Also refresh route from latest fix-from-domain (includes sip: + $rd domain)
bash "$SRC/deploy/fix-from-domain-trunk.sh"

# Belt-and-suspenders: any remaining From without sip:
python3 - "$CFG" <<'PY'
import re, sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()
text2 = text.replace(
    'append_hf("From: <$var(user)@$var(dom)>;tag=$ft\\r\\n");',
    'append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\\r\\n");',
)
text2 = text2.replace(
    'append_hf("From: <$var(user)@$var(dom)>;tag=$ft\r\n");',
    'append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\r\n");',
)
# common variant without escaped newlines in file
text2 = re.sub(
    r'append_hf\("From: <\$var\(user\)@\$var\(dom\)>;tag=\$ft\\r\\n"\);',
    'append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\\r\\n");',
    text2,
)
text2 = re.sub(
    r'append_hf\("From: <\$var\(user\)@\$var\(dom\)>;tag=\$ft\\r\\n"\);',
    'append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\\r\\n");',
    text2,
)
if 'From: <$var(user)' in text2 and 'From: <sip:$var(user)' not in text2:
    text2 = text2.replace('From: <$var(user)@$var(dom)>', 'From: <sip:$var(user)@$var(dom)>')
open(path, "w", encoding="utf-8").write(text2)
print("sip: scheme check done")
grep = [ln for ln in text2.splitlines() if 'append_hf("From:' in ln]
for ln in grep:
    print(" ", ln.strip())
    if "<sip:" not in ln:
        raise SystemExit("FATAL: From still missing sip:")
PY

opensips -c -f "$CFG"
systemctl restart opensips
sleep 2
systemctl is-active opensips

echo
echo "################################################################"
echo " Dial again. Expect:"
echo "   OWNSWITCH outbound From → sip:DID@trunk-host"
echo " Not 416 Unsupported URI scheme."
echo "################################################################"
