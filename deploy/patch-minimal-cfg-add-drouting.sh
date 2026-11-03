#!/usr/bin/env bash
# Extend a working minimal digest-REGISTER opensips.cfg with outbound drouting.
# Used when stage 50 cannot render the full template.
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || exit 1

CFG=/etc/opensips/opensips.cfg
[[ -f "$CFG" ]] || { echo "missing $CFG"; exit 1; }

PW=""
[[ -f /etc/ownswitch/secrets/mysql_opensips_password ]] && PW="$(tr -d '\n' </etc/ownswitch/secrets/mysql_opensips_password)"
[[ -n "$PW" ]] || PW="opensips"
DB_URL="$(python3 -c "import urllib.parse,sys; print('mysql://opensips:%s@127.0.0.1/opensips'%urllib.parse.quote(sys.argv[1],safe=''))" "$PW")"

MOD_DIR="$(dirname "$(find /usr/lib -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -1)")"
MPATH="${MOD_DIR}/"

# Ensure modules exist
for so in drouting.so dialog.so rtpengine.so cgrates.so permissions.so; do
  [[ -f "${MPATH}${so}" ]] || echo "WARN: missing ${so}"
done

python3 - "$CFG" "$DB_URL" "$MPATH" <<'PY'
import re, sys
path, db_url, mpath = sys.argv[1:4]
text = open(path, encoding="utf-8", errors="replace").read()

def ensure_mod(src, name, block_after=""):
    if f'loadmodule "{name}"' in src or f"loadmodule \"{name}\"" in src:
        return src
    insert = f'\nloadmodule "{name}"\n{block_after}'
    # after registrar
    m = re.search(r'loadmodule "registrar\.so".*\n', src)
    if m:
        return src[:m.end()] + insert + src[m.end():]
    return src + insert

# Add modules if missing
extras = [
    ('dialog.so', f'modparam("dialog", "db_url", "{db_url}")\nmodparam("dialog", "db_mode", 2)\nmodparam("dialog", "default_timeout", 7200)\n'),
    ('permissions.so', f'modparam("permissions", "db_url", "{db_url}")\n'),
    ('drouting.so', f'modparam("drouting", "db_url", "{db_url}")\nmodparam("drouting", "gw_attrs_avp", \'$avp(dr_gw_attrs)\')\n'),
    ('rtpengine.so', 'modparam("rtpengine", "rtpengine_sock", "udp:127.0.0.1:22222")\n'),
    ('cgrates.so', 'modparam("cgrates", "cgrates_engine", "127.0.0.1:2014")\n'),
    ('acc.so', f'modparam("acc", "db_url", "{db_url}")\nmodparam("acc", "early_media", 0)\nmodparam("acc", "report_cancels", 1)\n'),
]
for name, params in extras:
    if f'loadmodule "{name}"' not in text:
        text = ensure_mod(text, name, params)

# Replace "User not registered" dead-end with drouting outbound
old = '''	if (lookup("location")) {
		record_route();
		t_relay();
		exit;
	}

	send_reply(404, "User not registered");
}'''

new = '''	@@OPENSIPS_ALIAS_LOOKUP@@
	if (lookup("location")) {
		record_route();
		create_dialog("B");
		do_accounting("db", "cdr|missed|failed");
		rtpengine_manage();
		t_relay();
		exit;
	}

	record_route();
	create_dialog("B");
	if (!cgrates_auth()) {
		xlog("L_WARN", "cgrates_auth failed $fU -> $rU\\n");
		send_reply(403, "Billing Authorization Failed");
		exit;
	}
	cgrates_acc("cdr|missed");
	do_accounting("db", "cdr|missed|failed");
	rtpengine_manage();

	if (!do_routing("1")) {
		xlog("L_WARN", "no route for $rU from $fU\\n");
		send_reply(503, "No Route");
		exit;
	}
	t_on_failure("carrier_failover");
	t_relay();
}

failure_route[carrier_failover] {
	if (t_was_cancelled()) {
		exit;
	}
	if (use_next_gw()) {
		t_on_failure("carrier_failover");
		t_relay();
		exit;
	}
	rtpengine_delete();
}
'''

# alias lookup optional
new = new.replace('@@OPENSIPS_ALIAS_LOOKUP@@', '# alias_db optional')

if 'do_routing' in text:
    print("drouting already present")
else:
    if old not in text:
        # looser match
        text2, n = re.subn(
            r'if \(lookup\("location"\)\) \{.*?send_reply\(404,\s*"User not registered"\);\s*\}',
            new.rstrip() + "\n",
            text,
            count=1,
            flags=re.S,
        )
        if n != 1:
            print("FATAL: could not find INVITE lookup/404 block to patch", file=sys.stderr)
            sys.exit(1)
        text = text2
    else:
        text = text.replace(old, new)
    open(path, "w", encoding="utf-8").write(text)
    print("patched outbound drouting into", path)
PY

opensips -c -f "$CFG"
echo "patch-minimal-cfg-add-drouting: OK"
