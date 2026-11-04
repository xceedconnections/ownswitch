#!/usr/bin/env bash
# Fix Softphone Display Name → CallerID (Eyebeam) WITHOUT breaking DNC or REGISTER.
#
# Root causes this addresses:
#  1) Softphone IP in address ACL skips digest → load_credentials never runs →
#     os_cid_mode stays NULL → apply_sip_cid treats as passthrough → CLI = SIP username
#  2) append_hf(From) does not refresh $fU → ACC must use $dlg_val(os_cli)
#  3) Eyebeam puts digits in From display ($fn), username in $fU
#
# Usage: sudo bash /opt/ownswitch-src/deploy/fix-opensips-display-cli.sh
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }
CFG="${1:-/etc/opensips/opensips.cfg}"
SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
[[ -f "$CFG" ]] || { echo "Missing $CFG"; exit 1; }

echo "======== Softphone Display Name CLI fix ========"

ensure_col() {
  local col="$1" def="$2"
  local n
  n="$(mysql -Nuroot opensips -e "SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='opensips' AND TABLE_NAME='subscriber' AND COLUMN_NAME='${col}'" 2>/dev/null || echo 0)"
  if [[ "${n:-0}" -eq 0 ]]; then
    echo "ADD subscriber.${col}"
    mysql -uroot opensips -e "ALTER TABLE subscriber ADD COLUMN ${def}"
  else
    echo "OK subscriber.${col}"
  fi
}
ensure_col cid_mode "cid_mode VARCHAR(16) NOT NULL DEFAULT 'passthrough'"
ensure_col rpid "rpid VARCHAR(64) NULL"
ensure_col cid_min_len "cid_min_len INT UNSIGNED NOT NULL DEFAULT 0"
ensure_col cid_max_len "cid_max_len INT UNSIGNED NOT NULL DEFAULT 0"
ensure_col dnc_group_id "dnc_group_id INT UNSIGNED NOT NULL DEFAULT 0"
ensure_col dst_format "dst_format VARCHAR(16) NOT NULL DEFAULT 'as_is'"
ensure_col media_policy "media_policy VARCHAR(16) NOT NULL DEFAULT 'relay'"
ensure_col allow_calls "allow_calls TINYINT(1) NOT NULL DEFAULT 1"
ensure_col dtmf_pass "dtmf_pass VARCHAR(8) NOT NULL DEFAULT 'yes'"

echo "subscriber cid_mode (portal sync should set display_name):"
mysql -Nuroot opensips -e "SELECT username, cid_mode FROM subscriber" 2>/dev/null || true

BAK="${CFG}.bak-display-cli-$(date +%Y%m%d%H%M%S)"
cp -a "$CFG" "$BAK"
echo "Backup: $BAK"

python3 - "$CFG" <<'PY'
import re, sys

cfg = sys.argv[1]
text = open(cfg, encoding="utf-8", errors="replace").read()

def find_block(text: str, marker: str):
    start = text.find(marker)
    if start < 0:
        return None
    brace = text.find("{", start)
    if brace < 0:
        return None
    depth = 0
    i = brace
    while i < len(text):
        if text[i] == "{":
            depth += 1
        elif text[i] == "}":
            depth -= 1
            if depth == 0:
                return start, i + 1
        i += 1
    return None

def replace_route(text: str, name: str, body: str) -> str:
    marker = f"route[{name}]"
    found = find_block(text, marker)
    if not found:
        if "route[relay]" in text:
            text = text.replace("route[relay]", body.rstrip() + "\n\nroute[relay]", 1)
        else:
            text += "\n" + body
        print(f"inserted route[{name}]")
        return text
    start, end = found
    while end < len(text) and text[end] in " \t\r\n":
        end += 1
    text = text[:start] + body.rstrip() + "\n\n" + text[end:]
    print(f"replaced route[{name}]")
    return text

# --- 1) INVITE auth: on IP ACL still digest-load AVPs when Authorization present ---
# Surgical: keep carrier/digest else-body intact (alias lookups, etc.)
ACL_DIGEST = (
    'if (check_source_address(1)) {\n'
    '\t\tif (is_present_hf("Proxy-Authorization") || is_present_hf("Authorization")) {\n'
    '\t\t\tif (proxy_authorize("", "subscriber")) {\n'
    '\t\t\t\tconsume_credentials();\n'
    '\t\t\t} else {\n'
    '\t\t\t\txlog("L_WARN", "IP ACL ok but INVITE digest failed user=$fU from=$si (AVPs may be empty)\\n");\n'
    '\t\t\t}\n'
    '\t\t} else {\n'
    '\t\t\txlog("L_INFO", "IP ACL peer (no digest) from=$si user=$fU — CID prefs need sql load if used\\n");\n'
    '\t\t}\n'
    '\t} else {'
)

if "IP ACL ok but INVITE digest failed" in text:
    print("INVITE auth digest-on-ACL already present")
elif "if (!check_source_address(1)) {" in text:
    text = text.replace("if (!check_source_address(1)) {", ACL_DIGEST, 1)
    print("patched INVITE auth: !check_source_address → ACL + digest load_credentials")
else:
    m = re.search(
        r'if\s*\(\s*check_source_address\s*\(\s*1\s*\)\s*\)\s*\{\s*'
        r'(?:#[^\n]*\n\s*)*'
        r'(?:xlog\([^;]*;\s*)?'
        r'\}\s*else\s*\{',
        text,
        re.S,
    )
    if m:
        text = text[: m.start()] + ACL_DIGEST + text[m.end() :]
        print("patched INVITE auth: empty ACL trust → ACL + digest load_credentials")
    else:
        print("WARN: could not find INVITE check_source_address(1) opener — patch auth manually")

sip_cid = r'''route[apply_sip_cid] {
	$dlg_val(os_cli) = $fU;
	$var(mode) = $avp(os_cid_mode);
	$var(user) = $fU;
	$var(dom) = $fd;

	$var(dn) = $fn;
	if ($var(dn) == NULL || $(var(dn){s.len}) == 0) {
		$var(dn) = $(hdr(From){nameaddr.name});
	}
	if ($var(dn) == NULL || $(var(dn){s.len}) == 0) {
		$var(dn) = $(hdr(P-Asserted-Identity){nameaddr.name});
	}
	if ($var(dn) == NULL || $(var(dn){s.len}) == 0) {
		$var(dn) = $(hdr(Remote-Party-ID){nameaddr.name});
	}
	$var(dn_digits) = null;
	if ($var(dn) != NULL && $(var(dn){s.len}) > 0) {
		$var(dn_digits) = $(var(dn){re.subst,/[^0-9+]//g});
	}

	if (($var(mode) == NULL || $(var(mode){s.len}) == 0) && $var(dn_digits) != NULL
		&& $(var(dn_digits){s.len}) >= 3 && $(var(dn_digits){s.len}) <= 30
		&& !($fU =~ "^[+0-9]{8,30}$")) {
		$var(mode) = "display_name";
		xlog("L_INFO", "SIP CID mode inferred display_name (AVP empty) fn='$var(dn)'\n");
	}

	if ($var(mode) == NULL || $(var(mode){s.len}) == 0 || $var(mode) == "passthrough") {
		if ($var(dn_digits) != NULL && $(var(dn_digits){s.len}) >= 8 && $(var(dn_digits){s.len}) <= 30
			&& !($fU =~ "^[+0-9]{8,30}$")) {
			$dlg_val(os_cli) = $var(dn_digits);
			$acc_extra(cli) = $var(dn_digits);
			xlog("L_INFO", "SIP CID passthrough CDR-cli from display '$var(dn_digits)' (From user=$fU kept)\n");
		} else {
			xlog("L_INFO", "SIP CID passthrough From=$fU fn='$fn' mode='$var(mode)'\n");
		}
		return;
	}

	if ($var(mode) == "fixed" && $avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
		$var(user) = $(avp(os_cid){re.subst,/[^0-9+]//g});
	} else if ($var(mode) == "display_name") {
		if ($var(dn_digits) != NULL && $(var(dn_digits){s.len}) >= 3 && $(var(dn_digits){s.len}) <= 30
			&& ($var(dn_digits) =~ "^[+0-9]{3,30}$")) {
			$var(user) = $var(dn_digits);
		} else {
			xlog("L_WARN", "SIP CID display_name empty/invalid fn='$fn' dn='$var(dn)' From=$fU — set Eyebeam Display Name to digits\n");
			return;
		}
	} else {
		return;
	}
	$dlg_val(os_cli) = $var(user);
	$acc_extra(cli) = $var(user);
	remove_hf("From");
	append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\r\n");
	remove_hf("P-Asserted-Identity");
	append_hf("P-Asserted-Identity: <sip:$var(user)@$var(dom)>\r\n");
	remove_hf("Remote-Party-ID");
	append_hf("Remote-Party-ID: <sip:$var(user)@$var(dom)>;party=calling;privacy=off;screen=yes\r\n");
	xlog("L_INFO", "SIP CID mode=$var(mode) to sip:$var(user)@$var(dom) os_cli=$dlg_val(os_cli)\n");
}
'''

acc_cli = r'''route[acc_cli_gw] {
	$acc_extra(src) = $dlg_val(os_src);
	$acc_extra(dst) = $dlg_val(os_dst);
	$acc_extra(ip) = $si;
	if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {
		$acc_extra(cli) = $dlg_val(os_cli);
	} else {
		$acc_extra(cli) = $fU;
	}
	$var(gw) = $avp(dr_gw_id);
	if ($var(gw) == NULL || $(var(gw){s.len}) == 0) {
		$var(gw) = $avp(___dr_gw_id);
	}
	if (($var(gw) == NULL || $(var(gw){s.len}) == 0) && $avp(dr_gw_attrs) != NULL) {
		$var(tid) = $(avp(dr_gw_attrs){param.value,tid});
		if ($var(tid) != NULL && $(var(tid){s.len}) > 0) {
			$var(gw) = "T" + $var(tid);
		}
	}
	$acc_extra(gw) = $var(gw);
	xlog("L_INFO", "ACC cli=$acc_extra(cli) sip=$dlg_val(os_src) gw=$var(gw)\n");
}
'''

if "route[apply_sip_cid]" in text:
    text = replace_route(text, "apply_sip_cid", sip_cid)
elif "route[apply_outbound_cid]" in text:
    text = replace_route(text, "apply_outbound_cid", sip_cid.replace("apply_sip_cid", "apply_outbound_cid"))
else:
    text = replace_route(text, "apply_sip_cid", sip_cid)

text = replace_route(text, "acc_cli_gw", acc_cli)

if "route(apply_sip_cid)" not in text and "route(apply_outbound_cid)" not in text:
    for a in ("route(apply_trunk_attrs);", "route(acc_cli_gw);", "route(relay);", "\tt_relay();"):
        if a in text:
            text = text.replace(a, "route(apply_sip_cid);\n\t" + a, 1)
            print("inserted route(apply_sip_cid); before", a[:40])
            break

if "route(acc_cli_gw)" not in text:
    for a in ('xlog("L_INFO", "OWNSWITCH acc_extra before relay', "route(relay);", "\tt_relay();"):
        if a in text:
            text = text.replace(a, "route(acc_cli_gw);\n\t" + a, 1)
            print("inserted route(acc_cli_gw); before", a[:40])
            break

if "route[check_dnc]" in text:
    for anchor in ("route(acc_cli_gw);", 'xlog("L_INFO", "OWNSWITCH acc_extra before relay', 'xlog("L_INFO", "ACC cli='):
        if anchor in text:
            pinned = "route(check_dnc);\n\t" + anchor
            if pinned not in text:
                text = text.replace(anchor, pinned, 1)
                print("pinned check_dnc before", anchor[:40])
            break

CLI_BLOCK = (
    "if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {\n"
    "\t\t$acc_extra(cli) = $dlg_val(os_cli);\n"
    "\t} else {\n"
    "\t\t$acc_extra(cli) = $fU;\n"
    "\t}"
)
if "$acc_extra(cli) = $fU;" in text:
    text = text.replace("$acc_extra(cli) = $fU;", CLI_BLOCK)
    print("rewrote cli=$fU → os_cli")
if "$acc_extra(src) = $fU;" in text:
    text = text.replace("$acc_extra(src) = $fU;", "$acc_extra(src) = $dlg_val(os_src);")
    print("rewrote src=$fU → os_src")

lc = re.search(r'modparam\("auth_db",\s*"load_credentials",\s*"([^"]*)"\)', text)
if lc and "os_cid_mode" not in lc.group(1):
    creds = lc.group(1)
    if not creds.endswith(";"):
        creds += ";"
    creds += "$avp(os_cid_mode)=cid_mode;$avp(os_cid)=rpid;$avp(os_cid_min)=cid_min_len;$avp(os_cid_max)=cid_max_len"
    text = text[: lc.start(1)] + creds + text[lc.end(1) :]
    print("load_credentials += cid_mode")

if "$dlg_val(os_dst) = $rU;" in text and "$dlg_val(os_cli) = $fU;" not in text:
    text = text.replace("$dlg_val(os_dst) = $rU;", "$dlg_val(os_dst) = $rU;\n\t$dlg_val(os_cli) = $fU;", 1)

if 'is_method("REGISTER")' not in text and "is_method('REGISTER')" not in text:
    raise SystemExit("FATAL: REGISTER missing")

if text.count("{") != text.count("}"):
    raise SystemExit(
        "FATAL: brace mismatch after patch {=%d }=%d" % (text.count("{"), text.count("}"))
    )

open(cfg, "w", encoding="utf-8").write(text)
print("Patched", cfg)
PY

if ! opensips -c -f "$CFG"; then
  echo "ERROR: opensips -c failed — restoring $BAK" >&2
  cp -a "$BAK" "$CFG"
  exit 1
fi

systemctl restart opensips
sleep 1
if ! systemctl is-active --quiet opensips; then
  echo "FATAL: opensips down — restoring $BAK" >&2
  cp -a "$BAK" "$CFG"
  systemctl restart opensips || true
  exit 1
fi
fail2ban-client unban --all >/dev/null 2>&1 || true
bash "$SRC/deploy/fix-opensips-register.sh" "$CFG" || true

if [[ -f /opt/ownswitch/current/bin/sync-opensips-sip-users.php ]]; then
  sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
    php /opt/ownswitch/current/bin/sync-opensips-sip-users.php || true
fi

echo
echo "cid_mode after sync:"
mysql -Nuroot opensips -e "SELECT username, cid_mode FROM subscriber" 2>/dev/null || true
echo "DNC hooks: $(grep -c 'route(check_dnc)' "$CFG" || true)"
echo "apply_sip_cid calls: $(grep -c 'route(apply_sip_cid)' "$CFG" || true)"
echo "digest-on-ACL: $(grep -c 'IP ACL ok but INVITE digest failed' "$CFG" || true)"
echo "opensips: $(systemctl is-active opensips)"
echo
echo "Re-save SIP user if cid_mode != display_name."
echo "Eyebeam: Display Name = digits only (12138007000)."
echo "Test: journalctl -u opensips -f | grep -E 'SIP CID|ACC cli|DNC|REGISTER'"
echo "Expect: SIP CID mode=display_name … os_cli=1213…   OR   inferred display_name"
echo "        ACC cli=1213… sip=owner"
