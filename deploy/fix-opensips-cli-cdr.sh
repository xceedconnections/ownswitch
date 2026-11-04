#!/usr/bin/env bash
# Fix CDR CallerID when SIP mode = Softphone display name.
# Root cause: append_hf(From) does not refresh $fU, so ACC stored cli=SIP username.
# Fix: store presented CLI in $dlg_val(os_cli) and account that in acc_cli_gw.
#
# Usage: sudo bash /opt/ownswitch-src/deploy/fix-opensips-cli-cdr.sh
set -Eeuo pipefail
CFG="${1:-/etc/opensips/opensips.cfg}"
[[ -f "$CFG" ]] || { echo "Missing $CFG"; exit 1; }
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

BAK="${CFG}.bak-cli-$(date +%Y%m%d%H%M%S)"
cp -a "$CFG" "$BAK"
echo "Backup: $BAK"

python3 - "$CFG" <<'PY'
import re, sys
cfg = sys.argv[1]
text = open(cfg, encoding="utf-8", errors="replace").read()

def replace_route(text: str, name: str, body: str) -> str:
    # body includes "route[name] { ... }"
    pat = re.compile(rf'(?ms)^route\[{re.escape(name)}\]\s*\{{.*?\n\}}\s*')
    if pat.search(text):
        text = pat.sub(body.rstrip() + "\n\n", text, count=1)
        print(f"replaced route[{name}]")
    else:
        # insert before route[relay] or append
        if "route[relay]" in text:
            text = text.replace("route[relay]", body.rstrip() + "\n\nroute[relay]", 1)
        else:
            text += "\n" + body
        print(f"inserted route[{name}]")
    return text

sip_cid = r'''route[apply_sip_cid] {
	$dlg_val(os_cli) = $fU;
	if ($avp(os_cid_mode) == NULL || $(avp(os_cid_mode){s.len}) == 0 || $avp(os_cid_mode) == "passthrough") {
		return;
	}
	$var(user) = $fU;
	$var(dom) = $fd;
	if ($avp(os_cid_mode) == "fixed" && $avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
		$var(user) = $(avp(os_cid){re.subst,/[^0-9+]//g});
	} else if ($avp(os_cid_mode) == "display_name") {
		$var(dn) = $fn;
		if ($var(dn) != NULL && $(var(dn){s.len}) > 0) {
			$var(dn) = $(var(dn){re.subst,/[^0-9+]//g});
			if ($var(dn) =~ "^[+0-9]{3,30}$") {
				$var(user) = $var(dn);
			} else {
				xlog("L_WARN", "SIP CID display_name raw='$fn' — need digits for CLI\n");
				return;
			}
		} else {
			xlog("L_WARN", "SIP CID display_name empty — keeping From=$fU\n");
			return;
		}
	} else {
		return;
	}
	$dlg_val(os_cli) = $var(user);
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

# Also patch apply_outbound_cid alias if present (older live cfgs)
for name in ("apply_sip_cid", "apply_outbound_cid"):
    if f"route[{name}]" in text or f"route({name})" in text:
        body = sip_cid if name == "apply_sip_cid" else sip_cid.replace("apply_sip_cid", "apply_outbound_cid")
        text = replace_route(text, name, body)

if "route[acc_cli_gw]" in text or "route(acc_cli_gw)" in text:
    text = replace_route(text, "acc_cli_gw", acc_cli)
else:
    # Ensure call site exists before relay markers
    text = replace_route(text, "acc_cli_gw", acc_cli)
    for anchor in (
        'xlog("L_INFO", "OWNSWITCH acc_extra before relay',
        "route(relay);",
        "\tt_relay();",
    ):
        if anchor in text and "route(acc_cli_gw);" not in text.split(anchor, 1)[0][-80:]:
            text = text.replace(anchor, "route(acc_cli_gw);\n\t" + anchor, 1)
            print("inserted route(acc_cli_gw); before", anchor[:40])
            break

# Patch apply_trunk_attrs to prefer os_cli as base From user
trunk = r'''route[apply_trunk_attrs] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(attrs) = $avp(dr_gw_attrs);
	$var(fu) = $(var(attrs){param.value,fu});
	$var(fd) = $(var(attrs){param.value,fd});
	$var(cidadd) = $(var(attrs){param.value,cidadd});
	$var(cidrem) = $(var(attrs){param.value,cidrem});

	if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {
		$var(user) = $dlg_val(os_cli);
	} else {
		$var(user) = $fU;
	}
	if ($var(cidrem) != NULL && $(var(cidrem){s.len}) > 0) {
		$var(cidrem) = $(var(cidrem){s.unescape.param});
		if ($(var(user){s.substr,0,$(var(cidrem){s.len})}) == $var(cidrem)) {
			$var(user) = $(var(user){s.substr,$(var(cidrem){s.len}),0});
		}
	}
	if ($var(cidadd) != NULL && $(var(cidadd){s.len}) > 0) {
		$var(user) = $(var(cidadd){s.unescape.param}) + $var(user);
	}
	if ($var(fu) != NULL && $(var(fu){s.len}) > 0) {
		$var(user) = $(var(fu){s.unescape.param});
	}
	$var(user) = $(var(user){re.subst,/[^0-9A-Za-z+_-]//g});
	$var(dom) = $fd;
	if ($var(fd) != NULL && $(var(fd){s.len}) > 0) {
		$var(dom) = $(var(fd){s.unescape.param});
	}
	if (($var(fu) != NULL && $(var(fu){s.len}) > 0) || ($var(fd) != NULL && $(var(fd){s.len}) > 0)
		|| ($var(cidadd) != NULL && $(var(cidadd){s.len}) > 0) || ($var(cidrem) != NULL && $(var(cidrem){s.len}) > 0)) {
		$dlg_val(os_cli) = $var(user);
		remove_hf("From");
		append_hf("From: <sip:$var(user)@$var(dom)>;tag=$ft\r\n");
		remove_hf("P-Asserted-Identity");
		append_hf("P-Asserted-Identity: <sip:$var(user)@$var(dom)>\r\n");
		xlog("L_INFO", "trunk From rewrite to sip:$var(user)@$var(dom) os_cli=$dlg_val(os_cli)\n");
	}
}
'''
if "route[apply_trunk_attrs]" in text:
    text = replace_route(text, "apply_trunk_attrs", trunk)

# check_cid_len against os_cli
cid_len = r'''route[check_cid_len] {
	if ($dlg_val(os_cli) != NULL && $(dlg_val(os_cli){s.len}) > 0) {
		$var(clid) = $(dlg_val(os_cli){re.subst,/[^0-9]//g});
	} else {
		$var(clid) = $(fU{re.subst,/[^0-9]//g});
	}
	$var(clen) = $(var(clid){s.len});
	if ($avp(os_cid_min) != NULL && $(avp(os_cid_min){s.int}) > 0 && $var(clen) < $(avp(os_cid_min){s.int})) {
		xlog("L_WARN", "Wrong CallerID Length min user=$au cli=$var(clid) len=$var(clen)\n");
		send_reply(484, "Wrong CallerID Length");
		exit;
	}
	if ($avp(os_cid_max) != NULL && $(avp(os_cid_max){s.int}) > 0 && $var(clen) > $(avp(os_cid_max){s.int})) {
		xlog("L_WARN", "Wrong CallerID Length max user=$au cli=$var(clid) len=$var(clen)\n");
		send_reply(484, "Wrong CallerID Length");
		exit;
	}
}
'''
if "route[check_cid_len]" in text:
    text = replace_route(text, "check_cid_len", cid_len)

# Ensure load_credentials has cid_mode
lc = re.search(r'modparam\("auth_db",\s*"load_credentials",\s*"([^"]*)"\)', text)
if lc and "os_cid_mode" not in lc.group(1):
    creds = lc.group(1)
    if not creds.endswith(";"):
        creds += ";"
    creds += "$avp(os_cid_mode)=cid_mode;$avp(os_cid)=rpid"
    text = text[: lc.start(1)] + creds + text[lc.end(1) :]
    print("load_credentials: added cid_mode")

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
systemctl is-active opensips
echo
echo "OK. Place a test call from Eyebeam (Display Name = digits, CallerID mode = Softphone display)."
echo "Expect log:  ACC cli=1213… sip=owner"
echo "CDR CallerID column should show the display-name number, SIP column = owner."
