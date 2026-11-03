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
auto_aliases=no
mpath="@@OPENSIPS_MPATH@@"

socket=@@SIP_SOCKET_UDP@@
socket=@@SIP_SOCKET_TCP@@

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
modparam("auth_db", "calculate_ha1", 0)
modparam("auth_db", "password_column", "ha1")
# Load SIP Directmedia (media_policy column on subscriber) into $avp(os_media)
modparam("auth_db", "load_credentials", "$avp(os_media)=media_policy")
# Magnus-style: match subscriber by username only. Softphones often put the
# server IP in the Domain field while HA1 realm is OWNSWITCH_DOMAIN — use_domain=1 breaks that.
modparam("auth_db", "use_domain", 0)

loadmodule "usrloc.so"
modparam("usrloc", "working_mode_preset", "single-instance-sql-write-back")
modparam("usrloc", "db_url", "@@OPENSIPS_DB_URL@@")

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
modparam("acc", "report_cancels", 0)

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
	if (!mf_process_maxfwd_header(10)) {
		send_reply(483, "Too Many Hops");
		exit;
	}

	# SIP keep-alive towards us
	if (is_method("OPTIONS") && !$rU) {
		send_reply(200, "OK");
		exit;
	}

	# ---- in-dialog requests ----
	if (has_totag()) {
		if (loose_route()) {
			if (is_method("INVITE|ACK|BYE|UPDATE|PRACK") && $avp(os_media) != "direct") {
				rtpengine_manage();
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

	if (!is_method("INVITE|REGISTER|OPTIONS")) {
		send_reply(405, "Method Not Allowed");
		exit;
	}

	if (is_method("REGISTER")) {
		xlog("L_INFO", "REGISTER from $si:$sp user=$tU@$td\n");
		if (!www_authorize("@@OWNSWITCH_DOMAIN@@", "subscriber")) {
			xlog("L_WARN", "REGISTER auth failed for $tU realm=@@OWNSWITCH_DOMAIN@@ src=$si\n");
			www_challenge("@@OWNSWITCH_DOMAIN@@", "auth");
			exit;
		}
		@@OPENSIPS_NAT_REGISTER_BLOCK@@
		if (!save("location")) {
			xlog("L_ERR", "REGISTER save() failed for $tU\n");
			sl_reply_error();
		}
		xlog("L_INFO", "REGISTER OK $tU@$td from $si\n");
		exit;
	}

	if (is_method("OPTIONS")) {
		send_reply(200, "OK");
		exit;
	}

	# ---- INVITE ----
	if (check_source_address(2)) {
		# call coming from a carrier towards a registered customer endpoint
		route(inbound_from_carrier);
		exit;
	}

	if (!check_source_address(1)) {
		# not an IP-authenticated customer trunk: require SIP digest
		if (!proxy_authorize("@@OWNSWITCH_DOMAIN@@", "subscriber")) {
			proxy_challenge("@@OWNSWITCH_DOMAIN@@", "auth");
			exit;
		}
		consume_credentials();
	}

	record_route();
	create_dialog("B");

	# credit control: fail closed if CGRateS refuses or is unreachable
	if (!cgrates_auth()) {
		xlog("L_WARN", "cgrates_auth failed for $fU -> $rU from $si\n");
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
		send_reply(503, "No Route");
		exit;
	}

	route(apply_ruri_strip);
	route(apply_trunk_attrs);
	route(relay);
}

route[inbound_from_carrier] {
	record_route();
	create_dialog("B");
	# Rewrite DID (R-URI user) → SIP username via dbaliases (no-op if alias_db not loaded)
	@@OPENSIPS_ALIAS_LOOKUP@@
	if (!lookup("location")) {
		xlog("L_WARN", "inbound: no registration for $rU\n");
		send_reply(404, "Not Found");
		exit;
	}
	$avp(os_media) = "relay";
	route(apply_media);
	t_on_reply("handle_reply");
	route(relay);
}

# Direct = skip RTPengine; Relay = rtpengine_manage()
route[apply_media] {
	if ($avp(os_media) == "direct") {
		xlog("L_INFO", "media=direct for $fU (no rtpengine)\n");
		return;
	}
	rtpengine_manage();
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
		route(apply_trunk_attrs);
		t_on_failure("carrier_failover");
		t_relay();
		exit;
	}
	rtpengine_delete();
}
