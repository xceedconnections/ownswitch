#!/usr/bin/env bash
# Stage 50 - OpenSIPS 3.6 from apt.opensips.org (+ mysql, cgrates, rtpengine-capable modules)
# and the `opensips` database schema. Config is rendered, syntax-checked, then activated.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "50_opensips"

OPENSIPS_KEYRING="/usr/share/keyrings/opensips-org.gpg"
OPENSIPS_LIST="/etc/apt/sources.list.d/opensips.list"

# ---------------------------------------------------------------------------
# 1. Repository (documented at https://apt.opensips.org/)
# ---------------------------------------------------------------------------
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

# ---------------------------------------------------------------------------
# 2. Packages
# ---------------------------------------------------------------------------
fresh_install=0; pkg_installed opensips || fresh_install=1
(( fresh_install )) && apt_update || true
apt_install opensips opensips-mysql-module opensips-cgrates-module
# nice-to-have modules (names differ slightly between releases, so failures only warn)
apt_install_optional opensips-http-modules opensips-restclient-module opensips-json-module opensips-cli

installed="$(dpkg-query -W -f='${Version}' opensips 2>/dev/null || true)"
[[ "$installed" == ${OPENSIPS_VERSION_SERIES}.* ]] || die "OpenSIPS ${OPENSIPS_VERSION_SERIES}.x expected, got '${installed}'"
log_ok "OpenSIPS ${installed}"

# On a fresh install the packaged service must not keep running with the stock config
if (( fresh_install )); then systemctl stop opensips >/dev/null 2>&1 || true; fi

# ---------------------------------------------------------------------------
# 3. Database schema (idempotent: each module schema applied only if its first table is missing)
# ---------------------------------------------------------------------------
mysql_root -e 'SELECT 1' >/dev/null 2>&1 || die "MySQL not reachable - run stage 30 first"

mapfile -t sql_files < <(dpkg -L opensips-mysql-module 2>/dev/null | grep -E '/mysql/[a-z0-9_]+-create\.sql$' || true)
if ((${#sql_files[@]} == 0)); then
  mapfile -t sql_files < <(find /usr/share/opensips -type f -name '*-create.sql' -path '*mysql*' 2>/dev/null | sort || true)
fi
((${#sql_files[@]})) || die "OpenSIPS MySQL schema files not found (package opensips-mysql-module)"

schema_file() { local n=$1 f; for f in "${sql_files[@]}"; do [[ "$(basename "$f")" == "${n}-create.sql" ]] && { echo "$f"; return 0; }; done; return 1; }

apply_schema_once() { # module
  local mod=$1 f first
  f="$(schema_file "$mod" || true)"
  if [[ -z "$f" ]]; then log_warn "schema for module '${mod}' not shipped - skipped"; return 0; fi
  first="$(grep -iEo 'CREATE TABLE( IF NOT EXISTS)? +`?[a-z0-9_]+' "$f" | head -n1 | awk '{print $NF}' | tr -d '`' || true)"
  if [[ -n "$first" ]] && mysql_table_exists "${DB_OPENSIPS}" "$first"; then
    log_info "schema '${mod}' already present (table ${first})"
    return 0
  fi
  log_info "applying OpenSIPS schema '${mod}' from ${f}"
  mysql_root "${DB_OPENSIPS}" <"$f"
  note_change "applied OpenSIPS schema ${mod}"
}

# standard first (creates `version`, acc, subscriber, address, ...)
for mod in standard auth_db usrloc dialog permissions drouting dispatcher rtpengine dialplan domain group; do
  apply_schema_once "$mod"
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

if [[ "${PRIVATE_IP}" != "${PUBLIC_IP}" ]]; then
  export SIP_SOCKET_UDP="udp:${PRIVATE_IP}:${SIP_PORT} as ${PUBLIC_IP}:${SIP_PORT}"
  export SIP_SOCKET_TCP="tcp:${PRIVATE_IP}:${SIP_PORT} as ${PUBLIC_IP}:${SIP_PORT}"
else
  export SIP_SOCKET_UDP="udp:${PRIVATE_IP}:${SIP_PORT}"
  export SIP_SOCKET_TCP="tcp:${PRIVATE_IP}:${SIP_PORT}"
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
