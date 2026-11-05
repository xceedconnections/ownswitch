#!/usr/bin/env bash
# Stage 50 - OpenSIPS 3.6 from apt.opensips.org (+ mysql, cgrates, rtpengine-capable modules)
# and the `opensips` database schema. Config is rendered, syntax-checked, then activated.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "50_opensips"
log_info "OpenSIPS stage starting (apt skip when already installed)…"

OPENSIPS_KEYRING="/usr/share/keyrings/opensips-org.gpg"
OPENSIPS_LIST="/etc/apt/sources.list.d/opensips.list"

# ---------------------------------------------------------------------------
# 1. Repository + packages
# On routine updates OpenSIPS is already installed — skip apt (avoids silent
# hangs on unattended-upgrades / dpkg lock). Set OWNSWITCH_FORCE_APT=1 to reinstall.
# ---------------------------------------------------------------------------
fresh_install=0; pkg_installed opensips || fresh_install=1
skip_apt=0
if is_true "${OWNSWITCH_SKIP_APT:-0}"; then
  skip_apt=1
elif (( ! fresh_install )) && ! is_true "${OWNSWITCH_FORCE_APT:-0}"; then
  skip_apt=1
fi

if (( skip_apt )); then
  log_info "OpenSIPS already installed — skipping apt (set OWNSWITCH_FORCE_APT=1 to force)"
else
  if [[ ! -s "${OPENSIPS_KEYRING}" ]]; then
    download "https://apt.opensips.org/opensips-org.gpg" "${OPENSIPS_KEYRING}"
    chmod 0644 "${OPENSIPS_KEYRING}"
    note_change "installed OpenSIPS apt key"
  fi
  list_line="deb [signed-by=${OPENSIPS_KEYRING}] https://apt.opensips.org ${OS_CODENAME} ${OPENSIPS_VERSION_SERIES}-releases"
  if [[ "$(cat "${OPENSIPS_LIST}" 2>/dev/null || true)" != "${list_line}" ]]; then
    printf '%s\n' "${list_line}" >"${OPENSIPS_LIST}"
    note_change "wrote ${OPENSIPS_LIST}"
    apt_update
  fi
  (( fresh_install )) && apt_update || true
  # opensips-auth-modules provides auth.so; mysql-dbschema ships *-create.sql files
  apt_install opensips opensips-mysql-module opensips-mysql-dbschema opensips-cgrates-module opensips-auth-modules
  # nice-to-have modules (names differ slightly between releases, so failures only warn)
  apt_install_optional opensips-http-modules opensips-restclient-module opensips-json-module opensips-cli opensips-nathelper-module
fi
# Even when apt was skipped, ensure dbschema package is present for CREATE scripts
if (( ! skip_apt )); then
  apt_install_optional opensips-mysql-dbschema || true
fi

installed="$(dpkg-query -W -f='${Version}' opensips 2>/dev/null || true)"
[[ "$installed" == ${OPENSIPS_VERSION_SERIES}.* ]] || die "OpenSIPS ${OPENSIPS_VERSION_SERIES}.x expected, got '${installed}'"
log_ok "OpenSIPS ${installed}"

# On a fresh install the packaged service must not keep running with the stock config
if (( fresh_install )); then systemctl stop opensips >/dev/null 2>&1 || true; fi

# ---------------------------------------------------------------------------
# 3. Database schema (idempotent: each module schema applied only if its first table is missing)
# ---------------------------------------------------------------------------
mysql_root -e 'SELECT 1' >/dev/null 2>&1 || die "MySQL not reachable - run stage 30 first"

mapfile -t sql_files < <(dpkg -L opensips-mysql-module opensips-mysql-dbschema 2>/dev/null | grep -E '\.sql$' || true)
if ((${#sql_files[@]} == 0)); then
  mapfile -t sql_files < <(find /usr/share/opensips /usr/share/opensips-mysql-module /usr/share/doc \
    -type f \( -name '*-create.sql' -o -name '*create*.sql' \) 2>/dev/null | sort || true)
fi
if ((${#sql_files[@]} == 0)); then
  if mysql_table_exists "${DB_OPENSIPS}" subscriber && mysql_table_exists "${DB_OPENSIPS}" location; then
    log_warn "No packaged SQL schema files found — core tables already exist; continuing (install opensips-mysql-dbschema if you need fresh schemas)"
  else
    die "OpenSIPS MySQL schema files not found — apt install opensips-mysql-dbschema"
  fi
else
  log_info "Found ${#sql_files[@]} OpenSIPS SQL schema file(s)"
fi

schema_file() {
  local n=$1 f base
  for f in "${sql_files[@]}"; do
    base="$(basename "$f")"
    case "$base" in
      "${n}-create.sql"|"${n}.sql"|"opensips-${n}-create.sql"|"${n}-create.mysql.sql") echo "$f"; return 0 ;;
    esac
  done
  # Last resort: file whose name contains the module and 'create'
  for f in "${sql_files[@]}"; do
    base="$(basename "$f")"
    [[ "$base" == *"${n}"*create* ]] && { echo "$f"; return 0; }
  done
  return 1
}

# If subscriber is missing, apply ANY sql file that creates it
ensure_subscriber_schema() {
  mysql_table_exists "${DB_OPENSIPS}" subscriber && return 0
  local f
  for f in "${sql_files[@]}"; do
    if grep -qiE 'create table.*subscriber' "$f"; then
      log_info "Applying subscriber DDL from ${f}"
      mysql_root "${DB_OPENSIPS}" <"$f" || true
    fi
  done
  mysql_table_exists "${DB_OPENSIPS}" subscriber \
    || die "Could not create opensips.subscriber from packaged SQL files: ${sql_files[*]}"
}

# usrloc write-through + portal registration status require opensips.location
ensure_location_schema() {
  mysql_table_exists "${DB_OPENSIPS}" location && return 0
  local f
  f="$(schema_file usrloc || true)"
  if [[ -n "$f" ]]; then
    log_info "Applying usrloc/location DDL from ${f}"
    mysql_root "${DB_OPENSIPS}" <"$f" || true
  fi
  if ! mysql_table_exists "${DB_OPENSIPS}" location; then
    for f in "${sql_files[@]}"; do
      if grep -qiE 'create table.*\`?location\`?' "$f"; then
        log_info "Applying location DDL from ${f}"
        mysql_root "${DB_OPENSIPS}" <"$f" || true
      fi
    done
  fi
  if ! mysql_table_exists "${DB_OPENSIPS}" location; then
    log_warn "Packaged usrloc SQL missing — creating opensips.location fallback (OpenSIPS 3.6)"
    mysql_root "${DB_OPENSIPS}" <<'SQL'
CREATE TABLE IF NOT EXISTS location (
  contact_id BIGINT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  username CHAR(64) DEFAULT '' NOT NULL,
  domain CHAR(64) DEFAULT NULL,
  contact TEXT NOT NULL,
  received CHAR(255) DEFAULT NULL,
  path CHAR(255) DEFAULT NULL,
  expires INT(10) UNSIGNED NOT NULL,
  q FLOAT(10,2) DEFAULT 1.0 NOT NULL,
  callid CHAR(255) DEFAULT 'Default-Call-ID' NOT NULL,
  cseq INT(11) DEFAULT 13 NOT NULL,
  last_modified DATETIME DEFAULT '1900-01-01 00:00:01' NOT NULL,
  flags INT(11) DEFAULT 0 NOT NULL,
  cflags CHAR(255) DEFAULT NULL,
  user_agent CHAR(255) DEFAULT '' NOT NULL,
  socket CHAR(64) DEFAULT NULL,
  methods INT(11) DEFAULT NULL,
  sip_instance CHAR(255) DEFAULT NULL,
  kv_store TEXT(512) DEFAULT NULL,
  attr CHAR(255) DEFAULT NULL
) ENGINE=InnoDB;
INSERT INTO version (table_name, table_version)
  SELECT 'location', '1013' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM version WHERE table_name='location');
SQL
  fi
  mysql_table_exists "${DB_OPENSIPS}" location \
    || die "Could not create opensips.location (usrloc schema)"
}

apply_schema_once() { # module [required_table]
  local mod=$1 want=${2:-} f first
  f="$(schema_file "$mod" || true)"
  if [[ -z "$f" ]]; then log_warn "schema for module '${mod}' not shipped - skipped"; return 0; fi
  if [[ -n "$want" ]] && mysql_table_exists "${DB_OPENSIPS}" "$want"; then
    log_info "schema '${mod}' already present (table ${want})"
    return 0
  fi
  first="$(grep -iEo 'CREATE TABLE( IF NOT EXISTS)? +`?[a-z0-9_]+' "$f" | head -n1 | awk '{print $NF}' | tr -d '`' || true)"
  if [[ -z "$want" && -n "$first" ]] && mysql_table_exists "${DB_OPENSIPS}" "$first"; then
    log_info "schema '${mod}' already present (table ${first})"
    return 0
  fi
  log_info "applying OpenSIPS schema '${mod}' from ${f}"
  # Allow re-apply: ignore duplicate-object errors from partial previous runs
  if ! mysql_root "${DB_OPENSIPS}" <"$f"; then
    log_warn "schema '${mod}' apply returned non-zero — checking required table"
    if [[ -n "$want" ]] && ! mysql_table_exists "${DB_OPENSIPS}" "$want"; then
      die "OpenSIPS schema '${mod}' failed and table '${want}' is still missing"
    fi
  fi
  note_change "applied OpenSIPS schema ${mod}"
}

# standard first (creates `version`, acc, subscriber, address, ...)
# Pass the canonical table so a partial apply (only `version`) cannot skip the rest.
apply_schema_once standard subscriber
apply_schema_once usrloc location
for mod in auth_db dialog permissions drouting dispatcher rtpengine dialplan domain group; do
  apply_schema_once "$mod"
done
# Outbound REGISTER to providers (optional but needed for auth_mode=register trunks)
if (( ! skip_apt )); then
  apt_install_optional opensips-uac-module || true
fi
if pkg_installed opensips-uac-module || [[ -f /usr/lib/opensips/modules/uac_registrant.so ]] \
  || find /usr/lib -name 'uac_registrant.so' -path '*opensips*' 2>/dev/null | grep -q .; then
  apply_schema_once uac_registrant || apply_schema_once uac || true
  note_change "opensips-uac-module present"
else
  log_warn "opensips-uac-module not installed — IP peer trunks still work; Register-mode trunks will not"
fi
ensure_subscriber_schema
ensure_location_schema

# SIP Directmedia / DTMF / CID mode — loaded via auth_db load_credentials
mysql_root "${DB_OPENSIPS}" -e \
  "ALTER TABLE subscriber ADD COLUMN media_policy VARCHAR(16) NOT NULL DEFAULT 'relay'" 2>/dev/null \
  || log_info "subscriber.media_policy already present (or ALTER skipped)"
mysql_root "${DB_OPENSIPS}" -e \
  "ALTER TABLE subscriber ADD COLUMN dtmf_pass VARCHAR(8) NOT NULL DEFAULT 'yes'" 2>/dev/null || true
mysql_root "${DB_OPENSIPS}" -e \
  "ALTER TABLE subscriber ADD COLUMN cid_mode VARCHAR(16) NOT NULL DEFAULT 'passthrough'" 2>/dev/null || true

# Extra ACC fields for portal CDR import (matches acc extra_fields in opensips.cfg.tpl)
# cli = presented CallerID (Eyebeam Display Name / fixed); gw = drouting gwid (T{id})
for _acc_tbl in acc missed_calls; do
  mysql_root "${DB_OPENSIPS}" -e \
    "ALTER TABLE ${_acc_tbl} ADD COLUMN src_user VARCHAR(64) NULL; ALTER TABLE ${_acc_tbl} ADD COLUMN dst_user VARCHAR(64) NULL; ALTER TABLE ${_acc_tbl} ADD COLUMN src_ip VARCHAR(45) NULL; ALTER TABLE ${_acc_tbl} ADD COLUMN cli VARCHAR(64) NULL; ALTER TABLE ${_acc_tbl} ADD COLUMN gw VARCHAR(64) NULL" \
    2>/dev/null || true
  mysql_root "${DB_OPENSIPS}" -e "ALTER TABLE ${_acc_tbl} ADD COLUMN cli VARCHAR(64) NULL" 2>/dev/null || true
  mysql_root "${DB_OPENSIPS}" -e "ALTER TABLE ${_acc_tbl} ADD COLUMN gw VARCHAR(64) NULL" 2>/dev/null || true
done

# Make sure the portal/runtime account can use everything created by root
mysql_root -e "FLUSH PRIVILEGES"

# ---------------------------------------------------------------------------
# 4. Render configuration
# ---------------------------------------------------------------------------
pw_opensips="$(get_secret mysql_opensips_password)"
export OPENSIPS_DB_URL="mysql://opensips:${pw_opensips}@127.0.0.1/${DB_OPENSIPS}"

mod_dir="$(dirname "$(find /usr/lib -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -n1)")"
[[ -d "$mod_dir" && -f "$mod_dir/tm.so" ]] || die "Cannot locate OpenSIPS modules directory"
export OPENSIPS_MPATH="${mod_dir}/"
[[ -f "${mod_dir}/cgrates.so" ]] || die "cgrates.so missing in ${mod_dir} (package opensips-cgrates-module)"

# Prefer DNS A for OWNSWITCH_DOMAIN when that address is local (avoids Domain REGISTER → 500).
if [[ -n "${OWNSWITCH_DOMAIN:-}" ]]; then
  _dns_ip="$(getent ahostsv4 "${OWNSWITCH_DOMAIN}" 2>/dev/null | awk '{print $1; exit}' || true)"
  _local_ips="$(hostname -I 2>/dev/null || true)"
  if valid_ipv4 "${_dns_ip:-}" && [[ " ${_local_ips} " == *" ${_dns_ip} "* ]]; then
    PUBLIC_IP="${_dns_ip}"
    export PUBLIC_IP
    log_info "PUBLIC_IP aligned to DNS ${OWNSWITCH_DOMAIN} → ${PUBLIC_IP}"
  fi
fi
[[ -n "${OWNSWITCH_DOMAIN:-}" ]] || die "OWNSWITCH_DOMAIN is empty — cannot set SIP alias"
valid_ipv4 "${PUBLIC_IP}" || die "PUBLIC_IP='${PUBLIC_IP}' is not a valid IPv4 — Domain REGISTER will fail"

# Always bind all interfaces and advertise the public IP (Hetzner / cloud safe).
# Listening only on a private NIC caused softphones to time out even when TCP seemed open.
export SIP_SOCKET_UDP="udp:0.0.0.0:${SIP_PORT} as ${PUBLIC_IP}:${SIP_PORT}"
export SIP_SOCKET_TCP="tcp:0.0.0.0:${SIP_PORT} as ${PUBLIC_IP}:${SIP_PORT}"
export OWNSWITCH_DOMAIN
export RTPENGINE_SOCK="udp:127.0.0.1:${RTPENGINE_NG_PORT}"
cpus="$(nproc)"; workers=$(( cpus > 8 ? 8 : cpus )); (( workers >= 2 )) || workers=2
export OPENSIPS_UDP_WORKERS="${workers}"

if [[ -f "${mod_dir}/mi_http.so" && -f "${mod_dir}/httpd.so" ]]; then
  export OPENSIPS_MI_HTTP_BLOCK='loadmodule "httpd.so"
modparam("httpd", "ip", "127.0.0.1")
modparam("httpd", "port", 8888)
loadmodule "mi_http.so"
modparam("mi_http", "root", "mi")'
else
  export OPENSIPS_MI_HTTP_BLOCK='# mi_http not installed (opensips-http-modules) - MI available via FIFO only'
fi

# Prefer installing UAC so Register-mode trunks can outbound REGISTER to vendors (Magnus).
if (( ! skip_apt )); then
  apt_install_optional opensips-uac-module || true
fi
# uac_auth is a hard dependency of uac_registrant (digest REGISTER to vendors).
# OpenSIPS 3.6 defaults: table=registrant, columns registrar/aor/binding_URI/username/password.
mysql_root "${DB_OPENSIPS}" <<'SQL' 2>/dev/null || true
CREATE TABLE IF NOT EXISTS registrant (
  id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
  registrar CHAR(255) DEFAULT '' NOT NULL,
  proxy CHAR(255) DEFAULT NULL,
  aor CHAR(255) DEFAULT '' NOT NULL,
  third_party_registrant CHAR(255) DEFAULT NULL,
  username CHAR(64) DEFAULT NULL,
  password CHAR(64) DEFAULT NULL,
  binding_URI CHAR(255) DEFAULT '' NOT NULL,
  binding_params CHAR(64) DEFAULT NULL,
  expiry INT(11) UNSIGNED DEFAULT 0 NOT NULL,
  forced_socket CHAR(64) DEFAULT NULL,
  cluster_shtag CHAR(64) DEFAULT NULL,
  state INT DEFAULT 0 NOT NULL,
  description CHAR(64) DEFAULT NULL,
  KEY aor_idx (aor)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
SQL

if [[ -f "${mod_dir}/uac_registrant.so" ]]; then
  _uac_auth_load=""
  if [[ -f "${mod_dir}/uac_auth.so" ]]; then
    _uac_auth_load="loadmodule \"uac_auth.so\""
  fi
  export OPENSIPS_UAC_BLOCK="${_uac_auth_load}
loadmodule \"uac_registrant.so\"
modparam(\"uac_registrant\", \"db_url\", \"${OPENSIPS_DB_URL}\")
modparam(\"uac_registrant\", \"table_name\", \"registrant\")
modparam(\"uac_registrant\", \"timer_interval\", 20)
modparam(\"uac_registrant\", \"hash_size\", 2)
modparam(\"uac_registrant\", \"failure_retry_interval\", 60)"
else
  # refresh mod_dir in case package just landed
  mod_dir="$(dirname "$(find /usr/lib -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -n1)")"
  if [[ -f "${mod_dir}/uac_registrant.so" ]]; then
    export OPENSIPS_MPATH="${mod_dir}/"
    _uac_auth_load=""
    [[ -f "${mod_dir}/uac_auth.so" ]] && _uac_auth_load="loadmodule \"uac_auth.so\""
    export OPENSIPS_UAC_BLOCK="${_uac_auth_load}
loadmodule \"uac_registrant.so\"
modparam(\"uac_registrant\", \"db_url\", \"${OPENSIPS_DB_URL}\")
modparam(\"uac_registrant\", \"table_name\", \"registrant\")
modparam(\"uac_registrant\", \"timer_interval\", 20)
modparam(\"uac_registrant\", \"hash_size\", 2)"
  else
    export OPENSIPS_UAC_BLOCK='# uac_registrant.so not installed — IP peer trunks OK; Register-mode needs opensips-uac-module'
  fi
fi

# Softphones without Outbound Proxy need nathelper (rport + received)
if (( ! skip_apt )); then
  apt_install_optional opensips-nathelper-module || true
fi
if [[ -f "${mod_dir}/nathelper.so" ]]; then
  export OPENSIPS_NATHELPER_BLOCK='loadmodule "nathelper.so"
modparam("nathelper", "received_avp", "$avp(received)")'
  # OpenSIPS 3.x nat_uac_test uses named flags (not numeric 19)
  export OPENSIPS_NAT_REGISTER_BLOCK='force_rport();
		fix_nated_register();'
else
  export OPENSIPS_NATHELPER_BLOCK='# nathelper.so not installed (opensips-nathelper-module)'
  export OPENSIPS_NAT_REGISTER_BLOCK='# no nathelper — install opensips-nathelper-module for NAT softphones'
fi

# Extra aliases: every local IPv4 so Domain=<any NIC IP> works like Magnus
_extra_aliases=""
for _ip in $(hostname -I 2>/dev/null || true); do
  if valid_ipv4 "${_ip}" && [[ "${_ip}" != "${PUBLIC_IP}" ]]; then
    _extra_aliases="${_extra_aliases}alias=\"${_ip}\"
"
  fi
done
# localhost useful for probes / local Asterisk
_extra_aliases="${_extra_aliases}alias=\"127.0.0.1\"
"
export OPENSIPS_EXTRA_ALIASES="${_extra_aliases}"

# DID aliases (alias_db) — package often ships with opensips-mysql-module / alias_db.so
if (( ! skip_apt )); then
  apt_install_optional opensips-alias-db-module 2>/dev/null || true
fi
if [[ -f "${mod_dir}/alias_db.so" ]]; then
  export OPENSIPS_ALIAS_DB_BLOCK="loadmodule \"alias_db.so\"
modparam(\"alias_db\", \"db_url\", \"${OPENSIPS_DB_URL}\")"
  export OPENSIPS_ALIAS_LOOKUP='if (alias_db_lookup("dbaliases")) {
		xlog("L_INFO", "DID alias hit: now RURI=$ru\n");
	}'
  mysql_root "${DB_OPENSIPS}" -e "CREATE TABLE IF NOT EXISTS dbaliases (
    id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY NOT NULL,
    alias_username VARCHAR(64) NOT NULL DEFAULT '',
    alias_domain VARCHAR(64) NOT NULL DEFAULT '',
    username VARCHAR(64) NOT NULL DEFAULT '',
    domain VARCHAR(64) NOT NULL DEFAULT '',
    KEY alias_user_idx (alias_username, alias_domain)
  ) ENGINE=InnoDB" 2>/dev/null || true
else
  export OPENSIPS_ALIAS_DB_BLOCK='# alias_db.so missing — DID→SIP rewrite disabled until opensips-alias-db-module is installed'
  export OPENSIPS_ALIAS_LOOKUP='# no alias_db'
fi

# Rate CAP via exec → portal PHP (dial-cap-check.php)
if (( ! skip_apt )); then
  apt_install_optional opensips-module-exec 2>/dev/null || apt_install_optional opensips-exec-module 2>/dev/null || true
fi
PHP_BIN="$(command -v php || true)"
[[ -n "$PHP_BIN" ]] || PHP_BIN=/usr/bin/php
CAP_SCRIPT=/opt/ownswitch/current/bin/dial-cap-check.php
if [[ -f "${mod_dir}/exec.so" && -x "$PHP_BIN" ]]; then
  export OPENSIPS_EXEC_BLOCK='loadmodule "exec.so"'
  export OPENSIPS_RATE_CAP_ROUTE="route[check_rate_cap] {
	if (\$avp(dr_gw_attrs) == NULL) {
		return;
	}
	\$var(tid) = \$(avp(dr_gw_attrs){param.value,tid});
	if (\$var(tid) == NULL || \$(var(tid){s.len}) == 0) {
		return;
	}
	\$var(cap_user) = \$au;
	if (\$var(cap_user) == NULL || \$(var(cap_user){s.len}) == 0) {
		\$var(cap_user) = \$fU;
	}
	if (!exec(\"${PHP_BIN} ${CAP_SCRIPT} \$var(cap_user) \$rU \$var(tid)\")) {
		xlog(\"L_WARN\", \"Rate Cap Exceeded user=\$var(cap_user) dst=\$rU trunk=\$var(tid)\\n\");
		if (\$var(cap_tries) == NULL) {
			\$var(cap_tries) = 0;
		}
		\$var(cap_tries) = \$(var(cap_tries){s.int}) + 1;
		# use_next_gw only — do NOT call apply_* routes (may be undefined on patched live cfgs)
		if (\$(var(cap_tries){s.int}) < 8 && use_next_gw()) {
			route(check_rate_cap);
			return;
		}
		send_reply(403, \"Rate Cap Exceeded\");
		exit;
	}
}"
else
  export OPENSIPS_EXEC_BLOCK='# exec.so not installed — Rate CAP dial check disabled (apt install opensips-module-exec)'
  export OPENSIPS_RATE_CAP_ROUTE='route[check_rate_cap] {
	return;
}'
fi

# DNC: Redis SISMEMBER (preferred) or indexed MySQL dnc_cache — never PHP exec (dialer-safe)
if (( ! skip_apt )); then
  apt_install_optional opensips-module-cachedb-redis 2>/dev/null \
    || apt_install_optional opensips-cachedb-redis-module 2>/dev/null \
    || apt_install_optional opensips-redis-module 2>/dev/null || true
  apt_install_optional opensips-module-sqlops 2>/dev/null \
    || apt_install_optional opensips-sqlops-module 2>/dev/null || true
fi
REDIS_PASS=""
if [[ -s /etc/ownswitch/secrets/redis_password ]]; then
  REDIS_PASS="$(tr -d '\n' </etc/ownswitch/secrets/redis_password)"
fi
REDIS_DB="${REDIS_PORTAL_DB:-1}"
DNC_MODS=''
DNC_ROUTE='route[check_sip_dnc] {
	return;
}
route[check_dnc] {
	return;
}'
if [[ -f "${mod_dir}/cachedb_redis.so" ]]; then
  if [[ -n "${REDIS_PASS}" ]]; then
    REDIS_CDB_URL="redis://:${REDIS_PASS}@127.0.0.1:6379/${REDIS_DB}"
  else
    REDIS_CDB_URL="redis://127.0.0.1:6379/${REDIS_DB}"
  fi
  DNC_MODS+="loadmodule \"cachedb_redis.so\"
modparam(\"cachedb_redis\", \"cachedb_url\", \"redis:dnc=>${REDIS_CDB_URL}\")
"
  # Shared DNC helpers: SIP-user list blocks before vendor; trunk list uses original dialled digits
  DNC_ROUTE='route[check_sip_dnc] {
	if ($avp(os_dncid) == NULL || $(avp(os_dncid){s.int}) <= 0) {
		return;
	}
	$var(dncid) = $avp(os_dncid);
	$var(dnc_dst) = $(rU{re.subst,#[^0-9]##g});
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$var(dnc_key) = "ownswitch:dnc:" + $var(dncid);
	$var(dnc_hit) = NULL;
	if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_dst)", "$var(dnc_hit)") && $var(dnc_hit) != NULL && $(var(dnc_hit){s.int}) == 1) {
		xlog("L_WARN", "SIP DNC hit group=$var(dncid) dst=$var(dnc_dst) — block before trunk\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	# NANP alias: 11-digit 1NPANXX… ↔ 10-digit NPANXX…
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$var(dnc_hit) = NULL;
		if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_alt)", "$var(dnc_hit)") && $var(dnc_hit) != NULL && $(var(dnc_hit){s.int}) == 1) {
			xlog("L_WARN", "SIP DNC hit (NANP alt) group=$var(dncid) dst=$var(dnc_dst) alt=$var(dnc_alt) — block before trunk\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}
}
route[check_dnc] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(dncid) = $(avp(dr_gw_attrs){param.value,dncid});
	if ($var(dncid) == NULL || $(var(dncid){s.len}) == 0 || $(var(dncid){s.int}) <= 0) {
		return;
	}
	$var(dnc_dst) = $(dlg_val(os_dst){re.subst,#[^0-9]##g});
	if ($var(dnc_dst) == NULL || $(var(dnc_dst){s.len}) < 3) {
		$var(dnc_dst) = $(rU{re.subst,#[^0-9]##g});
	}
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$var(dnc_key) = "ownswitch:dnc:" + $var(dncid);
	$var(dnc_hit) = NULL;
	if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_dst)", "$var(dnc_hit)") && $var(dnc_hit) != NULL && $(var(dnc_hit){s.int}) == 1) {
		xlog("L_WARN", "Trunk DNC hit group=$var(dncid) dst=$var(dnc_dst) — instant block (no vendor)\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$var(dnc_hit) = NULL;
		if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_alt)", "$var(dnc_hit)") && $var(dnc_hit) != NULL && $(var(dnc_hit){s.int}) == 1) {
			xlog("L_WARN", "Trunk DNC hit (NANP alt) group=$var(dncid) dst=$var(dnc_dst) alt=$var(dnc_alt) — instant block\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}
}'
elif [[ -f "${mod_dir}/sqlops.so" ]]; then
  # OpenSIPS 3.6 sqlops: db_url without name=>; sql_query_one(query, "$var(x)")
  DNC_MODS+="loadmodule \"sqlops.so\"
modparam(\"sqlops\", \"db_url\", \"@@OPENSIPS_DB_URL@@\")
"
  DNC_ROUTE='route[check_sip_dnc] {
	if ($avp(os_dncid) == NULL || $(avp(os_dncid){s.int}) <= 0) {
		return;
	}
	$var(dncid) = $avp(os_dncid);
	$var(dnc_dst) = $(rU{re.subst,#[^0-9]##g});
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$var(dnc_hit) = NULL;
	if (sql_query_one("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='\''$var(dnc_dst)'\'' limit 1", "$var(dnc_hit)") && $var(dnc_hit) != NULL) {
		xlog("L_WARN", "SIP DNC hit (sql) group=$var(dncid) dst=$var(dnc_dst) — block before trunk\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$var(dnc_hit) = NULL;
		if (sql_query_one("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='\''$var(dnc_alt)'\'' limit 1", "$var(dnc_hit)") && $var(dnc_hit) != NULL) {
			xlog("L_WARN", "SIP DNC hit (sql NANP alt) group=$var(dncid) dst=$var(dnc_dst) alt=$var(dnc_alt)\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}
}
route[check_dnc] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(dncid) = $(avp(dr_gw_attrs){param.value,dncid});
	if ($var(dncid) == NULL || $(var(dncid){s.len}) == 0 || $(var(dncid){s.int}) <= 0) {
		return;
	}
	$var(dnc_dst) = $(dlg_val(os_dst){re.subst,#[^0-9]##g});
	if ($var(dnc_dst) == NULL || $(var(dnc_dst){s.len}) < 3) {
		$var(dnc_dst) = $(rU{re.subst,#[^0-9]##g});
	}
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$var(dnc_hit) = NULL;
	if (sql_query_one("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='\''$var(dnc_dst)'\'' limit 1", "$var(dnc_hit)") && $var(dnc_hit) != NULL) {
		xlog("L_WARN", "Trunk DNC hit (sql) group=$var(dncid) dst=$var(dnc_dst) — instant block (no vendor)\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$var(dnc_hit) = NULL;
		if (sql_query_one("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='\''$var(dnc_alt)'\'' limit 1", "$var(dnc_hit)") && $var(dnc_hit) != NULL) {
			xlog("L_WARN", "Trunk DNC hit (sql NANP alt) group=$var(dncid) dst=$var(dnc_dst) alt=$var(dnc_alt)\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}
}'
else
  DNC_MODS='# DNC modules missing — install opensips-module-cachedb-redis or opensips-module-sqlops'
  DNC_ROUTE='route[check_sip_dnc] {
	return;
}
route[check_dnc] {
	return;
}'
fi
export OPENSIPS_DNC_MODULES="${DNC_MODS}"
export OPENSIPS_DNC_ROUTE="${DNC_ROUTE}"

# Ensure OpenSIPS MySQL has dnc_cache for SQL fallback / dual-write from portal
mysql_root "${DB_OPENSIPS}" -e \
  "CREATE TABLE IF NOT EXISTS dnc_cache (
    group_id INT UNSIGNED NOT NULL,
    number_digits VARCHAR(32) NOT NULL,
    PRIMARY KEY (group_id, number_digits)
  ) ENGINE=InnoDB DEFAULT CHARSET=latin1" 2>/dev/null || true

# Failover SIP codes for failure_route t_check_status("408|480|…")
# Prefer portal system_settings; always export so @@FAILOVER_SIP_STATUS@@ never breaks render.
_failover_raw="$(mysql_root -N "${DB_OWNSWITCH}" -e \
  "SELECT setting_value FROM system_settings WHERE setting_key='failover_sip_codes' LIMIT 1" 2>/dev/null || true)"
_failover_raw="$(printf '%s' "${_failover_raw}" | tr -d '\r\n' | xargs 2>/dev/null || true)"
if [[ -z "${_failover_raw}" ]]; then
  _failover_raw="408,480,486,503"
fi
_failover_pat="$(printf '%s' "${_failover_raw}" | tr ',; ' '|||' | tr -s '|' | sed 's/^|//;s/|$//;s/[^0-9|]//g')"
[[ -n "${_failover_pat}" && "${_failover_pat}" != "|" ]] || _failover_pat="408|480|486|503"
export FAILOVER_SIP_STATUS="${_failover_pat}"
log_info "FAILOVER_SIP_STATUS=${FAILOVER_SIP_STATUS}"

# Render to a temp file first and validate BEFORE touching the live config.
tmp_cfg="$(mktemp /tmp/opensips.cfg.XXXXXX)"
trap 'rm -f "$tmp_cfg"' EXIT
render_to_stdout "${TEMPLATE_DIR}/opensips.cfg.tpl" >"$tmp_cfg" || die "opensips.cfg template render failed"

if ! check_out="$(opensips -c -f "$tmp_cfg" 2>&1)"; then
  printf '%s\n' "$check_out" >&2
  # Keep a copy for debugging (tmp is removed by EXIT trap)
  cp -f "$tmp_cfg" /tmp/opensips.cfg.ownswitch.bad 2>/dev/null || true
  echo "Bad config saved to /tmp/opensips.cfg.ownswitch.bad" >&2
  die "Generated OpenSIPS configuration failed syntax check (opensips -c). Live config untouched."
fi
log_ok "opensips -c: configuration OK"

render_template "${TEMPLATE_DIR}/opensips.cfg.tpl" /etc/opensips/opensips.cfg 0640 root opensips
cfg_changed="${RENDER_CHANGED}"

# Runtime dir for the MI fifo
install -d -m 0750 -o opensips -g opensips /run/opensips
if [[ ! -f /etc/tmpfiles.d/ownswitch-opensips.conf ]]; then
  echo "d /run/opensips 0750 opensips opensips -" >/etc/tmpfiles.d/ownswitch-opensips.conf
fi

# Package default may be disabled via /etc/default/opensips on some builds
if [[ -f /etc/default/opensips ]] && grep -qE '^RUN_OPENSIPS=no' /etc/default/opensips; then
  sed -i 's/^RUN_OPENSIPS=no/RUN_OPENSIPS=yes/' /etc/default/opensips
fi

# Always restart after stage 50 so digest REGISTER cfg is never left unloaded
systemctl stop opensips >/dev/null 2>&1 || true
fuser -k "${SIP_PORT}/udp" >/dev/null 2>&1 || true
fuser -k "${SIP_PORT}/tcp" >/dev/null 2>&1 || true
sleep 1
svc_apply opensips 1

# Digest REGISTER must be present in the live file
grep -q 'www_authorize' /etc/opensips/opensips.cfg \
  || die "opensips.cfg missing www_authorize after render — Ownswitch REGISTER auth not applied"
grep -q 'loadmodule "auth.so"' /etc/opensips/opensips.cfg \
  || die "opensips.cfg missing auth.so — install opensips-auth-modules"
[[ -f "${mod_dir}/auth.so" ]] \
  || die "auth.so missing under ${mod_dir} — apt install opensips-auth-modules"

# ---------------------------------------------------------------------------
# 5. Verify exposure
# ---------------------------------------------------------------------------
if [[ -f /etc/opensips/opensips.cfg ]] && ss -H -ltn 'sport = :8888' | grep -q . ; then
  ss -H -ltn 'sport = :8888' | awk '{print $4}' | grep -qx '127.0.0.1:8888' || die "OpenSIPS MI HTTP listening on non-loopback!"
fi

log_ok "OpenSIPS ${installed} running (SIP ${SIP_PORT}/udp+tcp, MI fifo ${OPENSIPS_FIFO})"
