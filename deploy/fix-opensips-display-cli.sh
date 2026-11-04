#!/usr/bin/env bash
# Surgical fix: Eyebeam Display Name → CDR CallerID / outbound From.
# Does NOT touch DNC routes. Does NOT restore cfg backups (won't wipe DNC).
# REGISTER-safe: ensures subscriber columns, unbans fail2ban, verifies opensips stays up.
#
# Usage: sudo bash /opt/ownswitch-src/deploy/fix-opensips-display-cli.sh
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }
CFG="${1:-/etc/opensips/opensips.cfg}"
[[ -f "$CFG" ]] || { echo "Missing $CFG"; exit 1; }

echo "======== Display-Name CLI fix (keep DNC) ========"

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
ensure_col cid_min_len "cid_min_len INT UNSIGNED NOT NULL DEFAULT 0"
ensure_col cid_max_len "cid_max_len INT UNSIGNED NOT NULL DEFAULT 0"
ensure_col rpid "rpid VARCHAR(64) NULL"
ensure_col dnc_group_id "dnc_group_id INT UNSIGNED NOT NULL DEFAULT 0"
ensure_col dst_format "dst_format VARCHAR(16) NOT NULL DEFAULT 'as_is'"
ensure_col media_policy "media_policy VARCHAR(16) NOT NULL DEFAULT 'relay'"
ensure_col allow_calls "allow_calls TINYINT(1) NOT NULL DEFAULT 1"

echo "Before: subscriber cid_mode"
mysql -Nuroot opensips -e "SELECT username, cid_mode FROM subscriber" 2>/dev/null || true

BAK="${CFG}.bak-display-cli-$(date +%Y%m%d%H%M%S)"
cp -a "$CFG" "$BAK"
echo "Backup: $BAK (not restored unless patch fails)"

python3 - "$CFG" <<'PY'
import re, sys
cfg = sys.argv[1]
text = open(cfg, encoding="utf-8", errors="replace").read()

def replace_route(text: str, name: str, body: str) -> str:
    marker = f"route[{name}]"
    start = text.find(marker)
    if start < 0:
        if "route[relay]" in text:
            text = text.replace("route[relay]", body.rstrip() + "\n\nroute[relay]", 1)
        else:
            text += "\n" + body
        print(f"inserted route[{name}]")
        return text
    brace = text.find("{", start)
    depth = 0
    i = brace
    while i < len(text):
        if text[i] == "{":
            depth += 1
        elif text[i] == "}":
            depth -= 1
            if depth == 0:
                end = i + 1
                while end < len(text) and text[end] in " \t\r\n":
                    end += 1
                text = text[:start] + body.rstrip() + "\n\n" + text[end:]
                print(f"replaced route[{name}]")
                return text
        i += 1
    raise SystemExit(f"unbalanced route[{name}]")

sip_cid = r'''route[apply_sip_cid] {
	$dlg_val(os_cli) = $fU;
	if ($avp(os_cid_mode) == NULL || $(avp(os_cid_mode){s.len}) == 0 || $avp(os_cid_mode) == "passthrough") {
		xlog("L_INFO", "SIP CID passthrough From=$fU fn='$fn'\n");
		return;
	}
	$var(user) = $fU;
	$var(dom) = $fd;
	if ($avp(os_cid_mode) == "fixed" && $avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
		$var(user) = $(avp(os_cid){re.subst,/[^0-9+]//g});
	} else if ($avp(os_cid_mode) == "display_name") {
		# Eyebeam: Display Name → From display ("1213…") <sip:user@host>
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
		xlog("L_INFO", "SIP CID display_name raw fn='$fn' dn='$var(dn)' mode=$avp(os_cid_mode)\n");
		if ($var(dn) != NULL && $(var(dn){s.len}) > 0) {
			$var(dn) = $(var(dn){re.subst,/[^0-9+]//g});
			if ($var(dn) =~ "^[+0-9]{3,30}$") {
				$var(user) = $var(dn);
			} else {
				xlog("L_WARN", "SIP CID display_name digits missing after strip dn='$var(dn)'\n");
				return;
			}
		} else {
			xlog("L_WARN", "SIP CID display_name empty — Eyebeam Display Name not in From\n");
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
	xlog("L_INFO", "SIP CID mode=$avp(os_cid_mode) to sip:$var(user)@$var(dom) os_cli=$dlg_val(os_cli)\n");
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

# Ensure call sites exist (after DNC check if present)
def ensure_call(text, route_call, anchors):
    if route_call in text:
        return text
    for a in anchors:
        if a in text:
            text = text.replace(a, route_call + "\n\t" + a, 1)
            print("inserted", route_call, "before", a[:40])
            return text
    print("WARN: no call site for", route_call)
    return text

# apply_sip_cid must run on INVITE outbound
if "route(apply_sip_cid)" not in text and "route(apply_outbound_cid)" not in text:
    text = ensure_call(text, "route(apply_sip_cid);", [
        "route(apply_trunk_attrs);",
        "route(acc_cli_gw);",
        'xlog("L_INFO", "OWNSWITCH acc_extra before relay',
        "route(relay);",
        "\tt_relay();",
    ])

if "route(acc_cli_gw)" not in text:
    text = ensure_call(text, "route(acc_cli_gw);", [
        'xlog("L_INFO", "OWNSWITCH acc_extra before relay',
        "route(relay);",
        "\tt_relay();",
    ])

# Order: check_dnc (keep) → apply_sip_cid → … → acc_cli_gw
# If check_dnc is after apply_sip_cid only, fine. Re-pin DNC before acc_cli_gw if DNC exists.
if "route[check_dnc]" in text:
    for anchor in ("route(acc_cli_gw);", 'xlog("L_INFO", "OWNSWITCH acc_extra before relay', 'xlog("L_INFO", "ACC cli='):
        if anchor in text:
            pinned = "route(check_dnc);\n\t" + anchor
            if pinned not in text:
                text = text.replace(anchor, pinned, 1)
                print("kept DNC: pinned check_dnc before", anchor[:40])
            break

# Rewrite stale cli=$fU
CLI_BLOCK = (
    "if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {\n"
    "\t\t$acc_extra(cli) = $dlg_val(os_cli);\n"
    "\t} else {\n"
    "\t\t$acc_extra(cli) = $fU;\n"
    "\t}"
)
if "$acc_extra(cli) = $fU;" in text:
    text = text.replace("$acc_extra(cli) = $fU;", CLI_BLOCK)
    print("rewrote acc_extra(cli)=$fU")

# before-relay: src must be SIP user, cli = os_cli
if "$acc_extra(src) = $fU;" in text:
    text = text.replace("$acc_extra(src) = $fU;", "$acc_extra(src) = $dlg_val(os_src);")
    print("rewrote before-relay src → os_src")

if "OWNSWITCH acc_extra before relay" in text and "$acc_extra(cli) = $dlg_val(os_cli)" not in text:
    text = text.replace(
        'xlog("L_INFO", "OWNSWITCH acc_extra before relay',
        'if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {\n'
        '\t\t$acc_extra(cli) = $dlg_val(os_cli);\n'
        '\t}\n'
        '\t$acc_extra(src) = $dlg_val(os_src);\n'
        '\txlog("L_INFO", "OWNSWITCH acc_extra before relay',
        1,
    )
    print("injected os_cli into before-relay")

# load_credentials must include cid_mode
lc = re.search(r'modparam\("auth_db",\s*"load_credentials",\s*"([^"]*)"\)', text)
if lc and "os_cid_mode" not in lc.group(1):
    creds = lc.group(1)
    if not creds.endswith(";"):
        creds += ";"
    creds += "$avp(os_cid_mode)=cid_mode;$avp(os_cid)=rpid;$avp(os_cid_min)=cid_min_len;$avp(os_cid_max)=cid_max_len"
    text = text[: lc.start(1)] + creds + text[lc.end(1) :]
    print("load_credentials += cid_mode")

# Init os_cli early
if "$dlg_val(os_dst) = $rU;" in text and "$dlg_val(os_cli) = $fU;" not in text:
    text = text.replace(
        "$dlg_val(os_dst) = $rU;",
        "$dlg_val(os_dst) = $rU;\n\t$dlg_val(os_cli) = $fU;",
        1,
    )
    print("init os_cli")

if 'is_method("REGISTER")' not in text and "is_method('REGISTER')" not in text:
    raise SystemExit("FATAL: REGISTER block missing")

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

# Rebuild load_credentials safely (does not wipe DNC routes)
bash /opt/ownswitch-src/deploy/fix-opensips-register.sh "$CFG" || true

# Force cid_mode=display_name from portal SIP users
if [[ -f /opt/ownswitch/current/bin/sync-opensips-sip-users.php ]]; then
  sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env \
    php /opt/ownswitch/current/bin/sync-opensips-sip-users.php || true
fi

echo
echo "After: subscriber cid_mode (must be display_name for Eyebeam CLI)"
mysql -Nuroot opensips -e "SELECT username, cid_mode FROM subscriber" 2>/dev/null || true
echo
echo "DNC still present?"; grep -c 'route(check_dnc)' "$CFG" || true
echo "apply_sip_cid calls:"; grep -c 'route(apply_sip_cid)' "$CFG" || true
echo
echo "opensips: $(systemctl is-active opensips)"
echo
echo "If cid_mode != display_name: re-save SIP user in portal (Softphone Display Name as CallerID)."
echo "Test call + watch:"
echo "  journalctl -u opensips -f | grep -E 'SIP CID|ACC cli|DNC|REGISTER'"
echo "Expect: SIP CID display_name raw … dn='1213…'"
echo "         SIP CID mode=display_name … os_cli=1213…"
echo "         ACC cli=1213… sip=owner"
