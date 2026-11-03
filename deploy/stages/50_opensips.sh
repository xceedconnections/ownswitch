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
  apt_install opensips opensips-mysql-module opensips-cgrates-module
  # nice-to-have modules (names differ slightly between releases, so failures only warn)
  apt_install_optional opensips-http-modules opensips-restclient-module opensips-json-module opensips-cli opensips-nathelper-module
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

mapfile -t sql_files < <(dpkg -L opensips-mysql-module 2>/dev/null | grep -E '\.sql$' || true)
if ((${#sql_files[@]} == 0)); then
  mapfile -t sql_files < <(find /usr/share/opensips /usr/share/opensips-mysql-module \
    -type f \( -name '*-create.sql' -o -name '*create*.sql' \) 2>/dev/null | sort || true)
fi
((${#sql_files[@]})) || die "OpenSIPS MySQL schema files not found (package opensips-mysql-module)"
log_info "Found ${#sql_files[@]} OpenSIPS SQL schema file(s)"

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
for mod in auth_db usrloc dialog permissions drouting dispatcher rtpengine dialplan domain group; do
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

# SIP Directmedia (Default/Direct/Relay) — loaded into $avp(os_media) via auth_db load_credentials
mysql_root "${DB_OPENSIPS}" -e \
  "ALTER TABLE subscriber ADD COLUMN media_policy VARCHAR(16) NOT NULL DEFAULT 'relay'" 2>/dev/null \
  || log_info "subscriber.media_policy already present (or ALTER skipped)"

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

# Always bind all interfaces and advertise the public IP (Hetzner / cloud safe).
# Listening only on a private NIC caused softphones to time out even when TCP seemed open.
if valid_ipv4 "${PUBLIC_IP}"; then
  export SIP_SOCKET_UDP="udp:0.0.0.0:${SIP_PORT} as ${PUBLIC_IP}:${SIP_PORT}"
  export SIP_SOCKET_TCP="tcp:0.0.0.0:${SIP_PORT} as ${PUBLIC_IP}:${SIP_PORT}"
else
  export SIP_SOCKET_UDP="udp:0.0.0.0:${SIP_PORT}"
  export SIP_SOCKET_TCP="tcp:0.0.0.0:${SIP_PORT}"
fi
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
if [[ -f "${mod_dir}/uac_registrant.so" ]]; then
  export OPENSIPS_UAC_BLOCK="loadmodule \"uac_registrant.so\"
modparam(\"uac_registrant\", \"db_url\", \"${OPENSIPS_DB_URL}\")
modparam(\"uac_registrant\", \"timer_interval\", 20)
modparam(\"uac_registrant\", \"hash_size\", 2)"
else
  # refresh mod_dir in case package just landed
  mod_dir="$(dirname "$(find /usr/lib -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -n1)")"
  if [[ -f "${mod_dir}/uac_registrant.so" ]]; then
    export OPENSIPS_MPATH="${mod_dir}/"
    export OPENSIPS_UAC_BLOCK="loadmodule \"uac_registrant.so\"
modparam(\"uac_registrant\", \"db_url\", \"${OPENSIPS_DB_URL}\")
modparam(\"uac_registrant\", \"timer_interval\", 20)"
  else
    export OPENSIPS_UAC_BLOCK='# uac_registrant.so not installed — IP peer trunks OK; Register-mode needs opensips-uac-module'
  fi
fi

if [[ -f "${mod_dir}/nathelper.so" ]]; then
  export OPENSIPS_NATHELPER_BLOCK='loadmodule "nathelper.so"
modparam("nathelper", "received_avp", "$avp(received)")'
  export OPENSIPS_NAT_REGISTER_BLOCK='if (nat_uac_test("19")) {
			fix_nated_register();
		}'
else
  export OPENSIPS_NATHELPER_BLOCK='# nathelper.so not installed (opensips-nathelper-module)'
  export OPENSIPS_NAT_REGISTER_BLOCK='# no nathelper'
fi

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

# Render to a temp file first and validate BEFORE touching the live config.
tmp_cfg="$(mktemp /tmp/opensips.cfg.XXXXXX)"
trap 'rm -f "$tmp_cfg"' EXIT
render_to_stdout "${TEMPLATE_DIR}/opensips.cfg.tpl" >"$tmp_cfg" || die "opensips.cfg template render failed"

if ! check_out="$(opensips -c -f "$tmp_cfg" 2>&1)"; then
  printf '%s\n' "$check_out" >&2
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

# restart only when the config changed (or it was stopped above)
svc_apply opensips "${cfg_changed}"

# ---------------------------------------------------------------------------
# 5. Verify exposure
# ---------------------------------------------------------------------------
if [[ -f /etc/opensips/opensips.cfg ]] && ss -H -ltn 'sport = :8888' | grep -q . ; then
  ss -H -ltn 'sport = :8888' | awk '{print $4}' | grep -qx '127.0.0.1:8888' || die "OpenSIPS MI HTTP listening on non-loopback!"
fi

log_ok "OpenSIPS ${installed} running (SIP ${SIP_PORT}/udp+tcp, MI fifo ${OPENSIPS_FIFO})"
