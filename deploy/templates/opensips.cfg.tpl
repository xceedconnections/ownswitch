#
# Ownswitch - OpenSIPS 3.6 baseline configuration
# Rendered by deploy/stages/50_opensips.sh from deploy/templates/opensips.cfg.tpl
# Placeholders use the at-at-NAME-at-at form (so OpenSIPS $variables are untouched).
#
# This is a BASELINE signalling + billing script:
#   customer auth (IP allow-list via `address` table group 1, else SIP digest)
#   -> dialog -> CGRateS authorisation/accounting -> RTPengine -> carrier selection (drouting)
# The Ownswitch provisioner may later replace/extend this file; always validate with
#   opensips -c -f /etc/opensips/opensips.cfg
# and keep the previous copy (the installer stores backups in /var/backups/ownswitch-config).
#
# address table groups:  1 = customer trunks (IP auth)   2 = carriers / upstream (inbound)
#

####### Global parameters #########

log_level=3
xlog_level=3
stderror_enabled=no
syslog_enabled=yes
syslog_facility=LOG_LOCAL0
log_name="opensips"

udp_workers=@@OPENSIPS_UDP_WORKERS@@
tcp_workers=4
# Advertise socket IPs + every host/IP we know (primary FQDN, public IP, extra local IPs)
auto_aliases=yes
mpath="@@OPENSIPS_MPATH@@"

socket=@@SIP_SOCKET_UDP@@
socket=@@SIP_SOCKET_TCP@@
# Primary portal domain + public IP (extra aliases injected by stage 50)
alias="@@OWNSWITCH_DOMAIN@@"
alias="@@PUBLIC_IP@@"
@@OPENSIPS_EXTRA_ALIASES@@

####### Modules #########

loadmodule "db_mysql.so"
loadmodule "proto_udp.so"
loadmodule "proto_tcp.so"
loadmodule "signaling.so"
loadmodule "sl.so"

loadmodule "tm.so"
modparam("tm", "fr_timeout", 5)
modparam("tm", "fr_inv_timeout", 60)
modparam("tm", "restart_fr_on_each_reply", 0)
modparam("tm", "onreply_avp_mode", 1)

loadmodule "rr.so"
modparam("rr", "append_fromtag", 0)

loadmodule "maxfwd.so"
loadmodule "sipmsgops.so"
loadmodule "uri.so"

loadmodule "mi_fifo.so"
modparam("mi_fifo", "fifo_name", "@@OPENSIPS_FIFO@@")
modparam("mi_fifo", "fifo_mode", 0660)
@@OPENSIPS_MI_HTTP_BLOCK@@

loadmodule "auth.so"
loadmodule "auth_db.so"
modparam("auth_db", "db_url", "@@OPENSIPS_DB_URL@@")
# Magnus-style: plaintext password + calculate HA1 using the softphone's realm.
# Domain may be public IP OR any FQDN that resolves here — no Outbound Proxy required.
# (calculate_ha1=0/ha1 forced one fixed realm and broke Domain=IP / alt-FQDN clients.)
modparam("auth_db", "calculate_ha1", 1)
modparam("auth_db", "password_column", "password")
# Load SIP Directmedia / DTMF pass / CallerID mode from subscriber into AVPs
modparam("auth_db", "load_credentials", "$avp(os_media)=media_policy;$avp(os_dtmf)=dtmf_pass;$avp(os_cid_mode)=cid_mode;$avp(os_cid)=rpid")
# Username-only match (ignore Domain in subscriber row)
modparam("auth_db", "use_domain", 0)

loadmodule "usrloc.so"
# write-through so portal registration status reads opensips.location live
modparam("usrloc", "working_mode_preset", "single-instance-sql-write-through")
modparam("usrloc", "db_url", "@@OPENSIPS_DB_URL@@")
# AOR = username only — Domain=IP and Domain=any-FQDN share the same binding
modparam("usrloc", "use_domain", 0)

loadmodule "registrar.so"
modparam("registrar", "max_contacts", 5)
modparam("registrar", "default_expires", 3600)

@@OPENSIPS_NATHELPER_BLOCK@@

loadmodule "permissions.so"
modparam("permissions", "db_url", "@@OPENSIPS_DB_URL@@")

# dialog MUST be loaded before cgrates
loadmodule "dialog.so"
modparam("dialog", "db_url", "@@OPENSIPS_DB_URL@@")
modparam("dialog", "db_mode", 2)
# hard cap on call length (seconds) - safety net for billing
modparam("dialog", "default_timeout", 7200)

# CGRateS SessionS bi-directional JSON-RPC (listen_bijson), loopback only
loadmodule "cgrates.so"
modparam("cgrates", "cgrates_engine", "@@CGR_BIJSON_ADDR@@")

loadmodule "rtpengine.so"
modparam("rtpengine", "rtpengine_sock", "@@RTPENGINE_SOCK@@")

# signalling accounting (backup / reconciliation feed next to CGRateS CDRs)
loadmodule "acc.so"
modparam("acc", "db_url", "@@OPENSIPS_DB_URL@@")
modparam("acc", "early_media", 0)
modparam("acc", "report_cancels", 1)
modparam("acc", "db_extra", "src_user=$fU;dst_user=$rU;src_ip=$si")

# dynamic routing (carrier selection / failover) - data loaded from the opensips DB
loadmodule "drouting.so"
modparam("drouting", "db_url", "@@OPENSIPS_DB_URL@@")
# Gateway attrs (from_user / from_domain / CID rewrite) pushed by Ownswitch trunk sync
modparam("drouting", "gw_attrs_avp", '$avp(dr_gw_attrs)')

# DID → SIP user aliases (Ownswitch DID assignments)
@@OPENSIPS_ALIAS_DB_BLOCK@@

# Outbound REGISTER towards providers that require digest registration (Magnus user/pass trunks).
# Optional: package opensips-uac-module. If the .so is missing, comment these two lines.
@@OPENSIPS_UAC_BLOCK@@

####### Routing logic #########

route {
	# =====================================================================
	# REGISTER FIRST — never proxy/relay (FQDN DNS to ourselves → 483/500).
	# Must run before Max-Forwards / loose_route / is_myself checks.
	# Accepts Domain = public IP OR any FQDN pointed at this server (no Proxy).
	# =====================================================================
	if (is_method("REGISTER")) {
		# Canonicalize non-IP Request-URI hosts → advertised IP (usrloc use_domain=0)
		if (!($rd =~ "^[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}$")) {
			rewritehost("@@PUBLIC_IP@@");
		}
		xlog("L_INFO", "REGISTER from $si:$sp user=$tU@$td ruri=$ru\n");
		# Empty realm → use Authorization realm; challenge with $td (phone Domain)
		if (!www_authorize("", "subscriber")) {
			xlog("L_WARN", "REGISTER auth failed for $tU@$td src=$si\n");
			www_challenge("$td", "auth");
			exit;
		}
		@@OPENSIPS_NAT_REGISTER_BLOCK@@
		if (!save("location")) {
			xlog("L_ERR", "REGISTER save() failed for $tU\n");
			sl_reply_error();
			exit;
		}
		xlog("L_INFO", "REGISTER OK $tU@$td from $si\n");
		exit;
	}

	if (!mf_process_maxfwd_header(10)) {
		send_reply(483, "Too Many Hops");
		exit;
	}

	# SIP keep-alive towards us
	if (is_method("OPTIONS") && !$rU) {
		send_reply(200, "OK");
		exit;
	}
	if (is_method("OPTIONS")) {
		send_reply(200, "OK");
		exit;
	}

	# ---- in-dialog requests ----
	if (has_totag()) {
		# Absorb DTMF (SIP INFO) when this dialog was created with dtmf_pass=no
		if (is_method("INFO") && $dlg_val(os_dtmf) == "no") {
			xlog("L_INFO", "DTMF INFO blocked in-dialog for $fU\n");
			send_reply(200, "OK");
			exit;
		}
		if (loose_route()) {
			if (is_method("INVITE|ACK|BYE|UPDATE|PRACK") && $avp(os_media) != "direct") {
				if ($avp(os_media) == "record") {
					rtpengine_manage("replace-session-connection replace-origin ICE=remove recording-label=$ci");
				} else {
					rtpengine_manage();
				}
			}
			route(relay);
		} else {
			if (is_method("ACK")) {
				if (t_check_trans()) {
					t_relay();
				}
				exit;
			}
			send_reply(404, "Not here");
		}
		exit;
	}

	# ---- initial requests ----
	if (is_method("CANCEL")) {
		if (t_check_trans()) {
			t_relay();
		}
		exit;
	}

	t_check_trans();

	if (!is_method("INVITE|OPTIONS")) {
		send_reply(405, "Method Not Allowed");
		exit;
	}

	# ---- INVITE ----
	# Customer IP ACL → skip digest. Carrier ACL → deliver only if local AOR exists;
	# otherwise fall through to digest (fixes softphone 404 when IP is also a trunk).
	if (!check_source_address(1)) {
		if (check_source_address(2)) {
			@@OPENSIPS_ALIAS_LOOKUP@@
			if (lookup("location")) {
				route(inbound_from_carrier_deliver);
				exit;
			}
			xlog("L_WARN", "carrier ACL but no AOR for $rU — customer digest path\n");
		}
		# Empty realm → match softphone Domain (IP or any FQDN); username-only DB lookup
		if (!proxy_authorize("", "subscriber")) {
			proxy_challenge("$fd", "auth");
			exit;
		}
		consume_credentials();
	}

	# DID → SIP user (when alias_db is loaded)
	@@OPENSIPS_ALIAS_LOOKUP@@

	# Local extension / SIP-user → registered AOR (username-only; Domain may be IP/FQDN)
	if (lookup("location")) {
		xlog("L_INFO", "local AOR hit for $rU from $fU\n");
		record_route();
		create_dialog("B");
		if ($avp(os_dtmf) != NULL) {
			$dlg_val(os_dtmf) = $avp(os_dtmf);
		} else {
			$dlg_val(os_dtmf) = "yes";
		}
		do_accounting("db", "cdr|missed|failed");
		if ($avp(os_media) == NULL || $(avp(os_media){s.len}) == 0) {
			$avp(os_media) = "relay";
		}
		route(apply_media);
		t_on_reply("handle_reply");
		route(relay);
		exit;
	}

	record_route();
	create_dialog("B");
	# Persist DTMF policy for in-dialog INFO filtering
	if ($avp(os_dtmf) != NULL) {
		$dlg_val(os_dtmf) = $avp(os_dtmf);
	} else {
		$dlg_val(os_dtmf) = "yes";
	}

	# credit control: fail closed if CGRateS refuses or is unreachable
	if (!cgrates_auth()) {
		xlog("L_WARN", "cgrates_auth failed for $fU -> $rU from $si\n");
		acc_db_request("403 Billing Authorization Failed", "acc");
		send_reply(403, "Billing Authorization Failed");
		exit;
	}
	cgrates_acc("cdr|missed");
	do_accounting("db", "cdr|missed|failed");

	# Media: $avp(os_media) loaded from subscriber.media_policy on digest auth (else relay)
	if ($avp(os_media) == NULL || $(avp(os_media){s.len}) == 0) {
		$avp(os_media) = "relay";
	}

	route(apply_media);
	t_on_reply("handle_reply");
	t_on_failure("carrier_failover");

	# Group "1" is filled by Ownswitch (php bin/sync-opensips-trunks.php / trunk+route save)
	# strip + pri_prefix are applied by drouting from dr_gateways
	if (!do_routing("1")) {
		xlog("L_WARN", "no route for $rU (from $fU) — sync trunks/routes to OpenSIPS\n");
		acc_db_request("503 No Route", "acc");
		send_reply(503, "No Route");
		exit;
	}

	route(apply_ruri_strip);
	route(apply_sip_cid);
	route(apply_trunk_attrs);
	route(relay);
}

# Called only after alias+lookup already succeeded for a carrier-sourced INVITE
route[inbound_from_carrier_deliver] {
	record_route();
	create_dialog("B");
	do_accounting("db", "cdr|missed|failed");
	$avp(os_media) = "relay";
	route(apply_media);
	t_on_reply("handle_reply");
	route(relay);
}

# Direct = skip RTPengine; Relay = rtpengine_manage(); Record = rtpengine + recording
# dtmf_pass=no → strip telephone-event so RFC2833 DTMF is not negotiated
route[apply_media] {
	$var(dtmf_flags) = "";
	if ($avp(os_dtmf) == "no") {
		$var(dtmf_flags) = " codec-strip-telephone-event";
	}
	if ($avp(os_media) == "direct") {
		xlog("L_INFO", "media=direct for $fU (no rtpengine)\n");
		return;
	}
	if ($avp(os_media) == "record") {
		xlog("L_INFO", "media=record for $fU (rtpengine + record)\n");
		rtpengine_manage("replace-session-connection replace-origin ICE=remove recording-label=$ci$var(dtmf_flags)");
		return;
	}
	if ($(var(dtmf_flags){s.len}) > 0) {
		rtpengine_manage("replace-session-connection replace-origin ICE=remove$var(dtmf_flags)");
		return;
	}
	rtpengine_manage();
}

# SIP-user CallerID policy (before trunk From override):
#   passthrough  = leave From as ViciDial / Eyebeam sent (portal CallerID empty)
#   fixed        = force portal CallerID (rpid / os_cid)
#   display_name = use Eyebeam/softphone Display Name as From user when it looks like a number
route[apply_sip_cid] {
	if ($avp(os_cid_mode) == NULL || $(avp(os_cid_mode){s.len}) == 0 || $avp(os_cid_mode) == "passthrough") {
		return;
	}
	$var(user) = $fU;
	$var(dom) = $fd;
	if ($avp(os_cid_mode) == "fixed" && $avp(os_cid) != NULL && $(avp(os_cid){s.len}) > 0) {
		$var(user) = $avp(os_cid);
	} else if ($avp(os_cid_mode) == "display_name") {
		$var(dn) = $fn;
		if ($var(dn) != NULL && $(var(dn){s.len}) > 0) {
			# Prefer digits from display name (Eyebeam Display Name used as CLI)
			$var(digits) = $(var(dn){s.select,0, });
			if ($var(digits) =~ "^[+0-9]{3,30}$") {
				$var(user) = $var(digits);
			} else if ($var(dn) =~ "^[+0-9]{3,30}$") {
				$var(user) = $var(dn);
			}
		}
	} else {
		return;
	}
	remove_hf("From");
	append_hf("From: <$var(user)@$var(dom)>;tag=$ft\r\n");
	xlog("L_INFO", "SIP CID mode=$avp(os_cid_mode) → $var(user)@$var(dom)\n");
}

# Multi remove-prefix (attrs rstrip=22,011,00) — strip tech prefix from RURI before send
route[apply_ruri_strip] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(attrs) = $avp(dr_gw_attrs);
	$var(rs) = $(var(attrs){param.value,rstrip});
	if ($var(rs) == NULL || $(var(rs){s.len}) == 0) {
		return;
	}
	$var(rs) = $(var(rs){s.unescape.param});
	$var(i) = 0;
	while ($var(i) < 8) {
		$var(p) = $(var(rs){s.select,$var(i),,});
		if ($var(p) == NULL || $(var(p){s.len}) == 0) {
			break;
		}
		if ($(rU{s.substr,0,$(var(p){s.len})}) == $var(p)) {
			$rU = $(rU{s.substr,$(var(p){s.len}),0});
			xlog("L_INFO", "trunk remove-prefix $var(p) → RURI user=$rU\n");
			break;
		}
		$var(i) = $var(i) + 1;
	}
}

# Apply Magnus Fromuser / Fromdomain / CID prefixes from gateway attrs (fu=;fd=;cidadd=;cidrem=)
# When fu= is set it ALWAYS overrides SIP-user CallerID and PBX-sent From (ViciDial etc.).
route[apply_trunk_attrs] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(attrs) = $avp(dr_gw_attrs);
	$var(fu) = $(var(attrs){param.value,fu});
	$var(fd) = $(var(attrs){param.value,fd});
	$var(cidadd) = $(var(attrs){param.value,cidadd});
	$var(cidrem) = $(var(attrs){param.value,cidrem});

	$var(user) = $fU;
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
	$var(dom) = $fd;
	if ($var(fd) != NULL && $(var(fd){s.len}) > 0) {
		$var(dom) = $(var(fd){s.unescape.param});
	}
	if (($var(fu) != NULL && $(var(fu){s.len}) > 0) || ($var(fd) != NULL && $(var(fd){s.len}) > 0)
		|| ($var(cidadd) != NULL && $(var(cidadd){s.len}) > 0) || ($var(cidrem) != NULL && $(var(cidrem){s.len}) > 0)) {
		remove_hf("From");
		append_hf("From: <$var(user)@$var(dom)>;tag=$ft\r\n");
		xlog("L_INFO", "trunk From rewrite → $var(user)@$var(dom)\n");
	}
}

route[relay] {
	if (!t_relay()) {
		send_reply(500, "Internal Error");
	}
	exit;
}

onreply_route[handle_reply] {
	if (has_body("application/sdp") && $avp(os_media) != "direct") {
		rtpengine_manage();
	}
}

failure_route[carrier_failover] {
	if (t_was_cancelled()) {
		exit;
	}
	# try next gateway of the selected route
	if (use_next_gw()) {
		route(apply_ruri_strip);
		route(apply_sip_cid);
		route(apply_trunk_attrs);
		t_on_failure("carrier_failover");
		t_relay();
		exit;
	}
	rtpengine_delete();
}
