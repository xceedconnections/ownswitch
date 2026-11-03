#!/usr/bin/env bash
# Eyebeam registered + portal REGISTERED but INVITE → 404 "User not registered"
# = live opensips.cfg still REGISTER-only OR broken drouting modparams.
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
grep -nE 'do_routing|User not registered|gw_attrs_avp|loadmodule "dialog|loadmodule "cgrates' "$CFG" | head -n 50 || true

echo "======== Sanitize / repair opensips.cfg ========"
PW=""
[[ -f /etc/ownswitch/secrets/mysql_opensips_password ]] && PW="$(tr -d '\n' </etc/ownswitch/secrets/mysql_opensips_password)"
[[ -n "$PW" ]] || PW="opensips"
DB_URL="$(python3 -c "import urllib.parse,sys; print('mysql://opensips:%s@127.0.0.1/opensips'%urllib.parse.quote(sys.argv[1],safe=''))" "$PW")"
MOD_DIR="$(dirname "$(find /usr/lib -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -1)")"
MPATH="${MOD_DIR}/"

python3 - "$CFG" "$DB_URL" "$MPATH" <<'PY'
import os, re, sys
path, db_url, mpath = sys.argv[1:4]
text = open(path, encoding="utf-8", errors="replace").read()
orig = text

# 1) Remove obsolete gw_attrs_avp lines (full line; value may contain parentheses)
text = re.sub(
    r"""^[ \t]*modparam\(\s*"drouting"\s*,\s*"gw_attrs_avp"\s*,\s*'[^'\n]*'\s*\)\s*\n?""",
    "",
    text,
    flags=re.M,
)
text = re.sub(
    r'''^[ \t]*modparam\(\s*"drouting"\s*,\s*"gw_attrs_avp"\s*,\s*"[^"\n]*"\s*\)\s*\n?''',
    "",
    text,
    flags=re.M,
)

# 2) Repair damage from prior bad regex that left orphan: ')
text = re.sub(r"""^[ \t]*'\)\s*\n""", "", text, flags=re.M)
text = re.sub(r'''^[ \t]*"\)\s*\n''', "", text, flags=re.M)

# 3) do_routing attrs via 5th arg (OpenSIPS 3.x)
if "do_routing(\"1\", , , , $avp(dr_gw_attrs))" not in text:
    text = re.sub(
        r'do_routing\(\s*"1"\s*\)',
        'do_routing("1", , , , $avp(dr_gw_attrs))',
        text,
    )

def ensure_mod(src, name, block_after=""):
    if f'loadmodule "{name}"' in src:
        return src
    insert = f'\nloadmodule "{name}"\n{block_after}'
    m = re.search(r'loadmodule "registrar\.so".*\n', src)
    if m:
        return src[: m.end()] + insert + src[m.end() :]
    return src + insert

# 4) dialog BEFORE cgrates (hard dependency)
dialog_so = os.path.join(mpath, "dialog.so")
cgrates_so = os.path.join(mpath, "cgrates.so")
if os.path.isfile(dialog_so):
    text = ensure_mod(
        text,
        "dialog.so",
        f'modparam("dialog", "db_url", "{db_url}")\n'
        f'modparam("dialog", "db_mode", 2)\n'
        f'modparam("dialog", "default_timeout", 7200)\n',
    )
else:
    # Cannot satisfy cgrates — drop it so opensips can start
    if 'loadmodule "cgrates.so"' in text:
        text = re.sub(r'^[ \t]*loadmodule\s+"cgrates\.so"\s*\n?', "", text, flags=re.M)
        text = re.sub(r'^[ \t]*modparam\(\s*"cgrates"[^)]*\)\s*\n?', "", text, flags=re.M)
        print("WARN: no dialog.so — removed cgrates loadmodule")

# If cgrates is loaded but dialog still missing, strip cgrates
if 'loadmodule "cgrates.so"' in text and 'loadmodule "dialog.so"' not in text:
    text = re.sub(r'^[ \t]*loadmodule\s+"cgrates\.so"\s*\n?', "", text, flags=re.M)
    text = re.sub(
        r'^[ \t]*modparam\(\s*"cgrates"\s*,\s*"[^"]*"\s*,\s*[^)\n]+\)\s*\n?',
        "",
        text,
        flags=re.M,
    )
    print("WARN: stripped cgrates (dialog not loaded)")

# Move dialog loadmodule block above cgrates if both present and dialog is after
if 'loadmodule "cgrates.so"' in text and 'loadmodule "dialog.so"' in text:
    di = text.find('loadmodule "dialog.so"')
    ci = text.find('loadmodule "cgrates.so"')
    if di > ci:
        # extract dialog loadmodule + following modparam("dialog"... lines
        m = re.search(
            r'loadmodule "dialog\.so"\n(?:modparam\("dialog"[^\n]*\n)*',
            text,
        )
        if m:
            block = m.group(0)
            text = text[: m.start()] + text[m.end() :]
            ci = text.find('loadmodule "cgrates.so"')
            text = text[:ci] + block + text[ci:]
            print("reordered dialog before cgrates")

open(path, "w", encoding="utf-8").write(text)
print("wrote", path)
if text != orig:
    print("cfg changed")
else:
    print("cfg unchanged after sanitize")
PY

echo "======== Ensure packages / tables ========"
export DEBIAN_FRONTEND=noninteractive
apt-get install -y opensips-mysql-dbschema opensips-mysql-module \
  opensips-auth-modules opensips-nathelper-module opensips-dialog-module 2>/dev/null || true
apt-get install -y opensips-cgrates-module 2>/dev/null || true

bash "$SRC/deploy/patch-minimal-cfg-add-drouting.sh"

# Final sanitize (safe regex only)
python3 - "$CFG" "$DB_URL" "$MPATH" <<'PY'
import os, re, sys
path, db_url, mpath = sys.argv[1:4]
text = open(path, encoding="utf-8", errors="replace").read()
text2 = re.sub(
    r"""^[ \t]*modparam\(\s*"drouting"\s*,\s*"gw_attrs_avp"\s*,\s*'[^'\n]*'\s*\)\s*\n?""",
    "",
    text,
    flags=re.M,
)
text2 = re.sub(r"""^[ \t]*'\)\s*\n""", "", text2, flags=re.M)
if 'loadmodule "dialog.so"' not in text2 and os.path.isfile(os.path.join(mpath, "dialog.so")):
    insert = (
        f'\nloadmodule "dialog.so"\n'
        f'modparam("dialog", "db_url", "{db_url}")\n'
        f'modparam("dialog", "db_mode", 2)\n'
        f'modparam("dialog", "default_timeout", 7200)\n'
    )
    m = re.search(r'loadmodule "registrar\.so".*\n', text2)
    text2 = (text2[: m.end()] + insert + text2[m.end() :]) if m else (text2 + insert)
if 'loadmodule "cgrates.so"' in text2 and 'loadmodule "dialog.so"' not in text2:
    text2 = re.sub(r'^[ \t]*loadmodule\s+"cgrates\.so"\s*\n?', "", text2, flags=re.M)
    text2 = re.sub(r'^[ \t]*modparam\(\s*"cgrates"[^\n]*\n?', "", text2, flags=re.M)
if text2 != text:
    open(path, "w", encoding="utf-8").write(text2)
    print("final sanitize applied")
PY

echo "======== AFTER ========"
grep -nE 'do_routing|User not registered|gw_attrs_avp|loadmodule "dialog|loadmodule "cgrates' "$CFG" | head -n 50 || true
grep -q 'gw_attrs_avp' "$CFG" && { echo "FATAL: gw_attrs_avp still present"; exit 1; }
grep -q 'do_routing' "$CFG" || { echo "FATAL: no do_routing"; exit 1; }
grep -q 'User not registered' "$CFG" && { echo "FATAL: dead-end still present"; exit 1; }

echo "======== opensips -c ========"
opensips -c -f "$CFG"

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

echo "======== location ========"
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
echo " PSTN needs gateways>0 (currently: ${GW})."
if [[ "${GW}" == "0" ]]; then
  echo " ADD portal Trunks + Routes, then:"
  echo "   sudo -u ownswitch env OWNSWITCH_ENV_FILE=$ENV_FILE php $CUR/bin/sync-opensips-trunks.php"
fi
echo "################################################################"
