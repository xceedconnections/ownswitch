#!/usr/bin/env bash
# Stage 60 - CGRateS v0.10.4: Redis DataDB (index 10) + MySQL StorDB (separate `cgrates` database).
#
# Install method (deterministic, auditable):
#   default: build the git tag ${CGRATES_VERSION} with Go from Ubuntu's golang-go (takes a few minutes).
#   override: CGRATES_BIN_TARBALL_URL=<tar.gz containing cgr-engine, cgr-migrator, ... + optional data/>
#             (+ CGRATES_BIN_TARBALL_SHA256) for air-gapped / pre-built binaries.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "60_cgrates"

BIN_DIR="/usr/local/bin"
SHARE_DIR="/usr/share/cgrates"
VERSION_MARK="${SHARE_DIR}/.installed-version"
CGR_BINS=(cgr-engine cgr-migrator cgr-loader cgr-console cgr-tester)

mysql_root -e 'SELECT 1' >/dev/null 2>&1 || die "MySQL not reachable - run stage 30 first"
systemctl is-active --quiet redis-server || die "Redis not running - run stage 40 first"

ensure_system_user cgrates /var/lib/cgrates
install -d -m 0750 -o cgrates -g cgrates /var/lib/cgrates /var/log/cgrates
install -d -m 0755 "${SHARE_DIR}"

# ---------------------------------------------------------------------------
# 1. Binaries
# ---------------------------------------------------------------------------
installed_ok=0
if [[ -x "${BIN_DIR}/cgr-engine" && "$(cat "${VERSION_MARK}" 2>/dev/null || true)" == "${CGRATES_VERSION}" ]]; then
  installed_ok=1; log_ok "CGRateS ${CGRATES_VERSION} already installed"
fi

if (( ! installed_ok )); then
  work="$(mktemp -d /tmp/cgrates-build.XXXXXX)"
  trap 'rm -rf "$work"' EXIT

  if [[ -n "${CGRATES_BIN_TARBALL_URL}" ]]; then
    log_info "Installing CGRateS from ${CGRATES_BIN_TARBALL_URL}"
    download "${CGRATES_BIN_TARBALL_URL}" "$work/cgr.tgz" "${CGRATES_BIN_TARBALL_SHA256}"
    mkdir -p "$work/x"; tar -xzf "$work/cgr.tgz" -C "$work/x"
    for b in "${CGR_BINS[@]}"; do
      src="$(find "$work/x" -type f -name "$b" | head -n1 || true)"
      [[ -n "$src" ]] && install -m 0755 "$src" "${BIN_DIR}/$b"
    done
    datadir="$(find "$work/x" -type d -name storage -path '*data*' | head -n1 || true)"
    if [[ -n "$datadir" ]]; then rsync -a "$(dirname "$datadir")/" "${SHARE_DIR}/"; fi
  else
    log_info "Building CGRateS ${CGRATES_VERSION} from source (go build) - this can take 5-15 minutes"
    apt_install git build-essential golang-go
    export GOPATH="/var/cache/ownswitch-build/go" GOCACHE="/var/cache/ownswitch-build/gocache" GOTOOLCHAIN=local
    install -d -m 0755 "$GOPATH" "$GOCACHE"
    git clone --quiet --depth 1 --branch "${CGRATES_VERSION}" https://github.com/cgrates/cgrates.git "$work/src"
    ( cd "$work/src"
      for b in "${CGR_BINS[@]}"; do
        log_info "go build ${b}"
        go build -o "${BIN_DIR}/${b}" "./cmd/${b}"
      done
      # schema + sample data shipped with the release
      rsync -a --delete data/storage/ "${SHARE_DIR}/storage/"
      rsync -a --delete data/tariffplans/ "${SHARE_DIR}/tariffplans/"
    )
  fi
  [[ -x "${BIN_DIR}/cgr-engine" ]] || die "cgr-engine was not installed"
  printf '%s\n' "${CGRATES_VERSION}" >"${VERSION_MARK}"
  note_change "installed CGRateS ${CGRATES_VERSION} to ${BIN_DIR}"
  rm -rf "$work"; trap - EXIT
fi

ver_out="$("${BIN_DIR}/cgr-engine" -version 2>&1 || true)"
log_info "cgr-engine: ${ver_out}"
case "${ver_out}" in *0.10*) : ;; *) log_warn "cgr-engine -version does not mention 0.10 - verify the binary" ;; esac

# ---------------------------------------------------------------------------
# 2. StorDB schema in its own database (idempotent)
# ---------------------------------------------------------------------------
mysql_dir="${SHARE_DIR}/storage/mysql"
[[ -d "$mysql_dir" ]] || die "CGRateS MySQL schema directory missing: ${mysql_dir}"

schema_applied=0
if ! mysql_table_exists "${DB_CGRATES}" "cdrs"; then
  [[ -f "${mysql_dir}/create_cdrs_tables.sql" ]] || die "create_cdrs_tables.sql not found"
  mysql_root "${DB_CGRATES}" <"${mysql_dir}/create_cdrs_tables.sql"
  note_change "applied CGRateS create_cdrs_tables.sql"; schema_applied=1
fi
if ! mysql_table_exists "${DB_CGRATES}" "tp_rates"; then
  [[ -f "${mysql_dir}/create_tariffplan_tables.sql" ]] || die "create_tariffplan_tables.sql not found"
  mysql_root "${DB_CGRATES}" <"${mysql_dir}/create_tariffplan_tables.sql"
  note_change "applied CGRateS create_tariffplan_tables.sql"; schema_applied=1
fi

# ---------------------------------------------------------------------------
# 3. Configuration (contains DB passwords -> root:cgrates 0640, never world readable)
# ---------------------------------------------------------------------------
export REDIS_PASSWORD CGRATES_DB_PASSWORD
REDIS_PASSWORD="$(get_secret redis_password)"
CGRATES_DB_PASSWORD="$(get_secret mysql_cgrates_password)"

install -d -m 0750 -o root -g cgrates /etc/cgrates
render_template "${TEMPLATE_DIR}/cgrates.json.tpl" /etc/cgrates/cgrates.json 0640 root cgrates
cfg_changed="${RENDER_CHANGED}"

# Record schema versions (engine refuses to start on a StorDB/DataDB without them).
if (( schema_applied )) || ! mysql_table_exists "${DB_CGRATES}" "versions"; then
  log_info "Setting CGRateS storage versions via cgr-migrator"
  if ! "${BIN_DIR}/cgr-migrator" -config_path=/etc/cgrates -exec='*set_versions' >/tmp/cgr-migrator.out 2>&1; then
    cat /tmp/cgr-migrator.out >&2 || true
    log_warn "cgr-migrator *set_versions failed. If cgr-engine refuses to start, run: cgr-migrator -config_path=/etc/cgrates -exec='*set_versions' -stordb_...  (see docs/TROUBLESHOOTING.md)"
  fi
fi

# ---------------------------------------------------------------------------
# 4. systemd service
# ---------------------------------------------------------------------------
render_template "${TEMPLATE_DIR}/systemd/cgrates.service" /etc/systemd/system/cgrates.service 0644 root root
[[ "${RENDER_CHANGED}" == "1" ]] && { daemon_reload; cfg_changed=1; }
svc_apply cgrates "${cfg_changed}"

# ---------------------------------------------------------------------------
# 5. Verify: JSON-RPC answers on loopback, nothing public
# ---------------------------------------------------------------------------
wait_for_tcp 127.0.0.1 2080 30 || { journalctl -u cgrates -n 40 --no-pager >&2 || true; die "CGRateS HTTP API (127.0.0.1:2080) not reachable"; }
pong="$(curl -fsS --max-time 5 -H 'Content-Type: application/json' \
  -d '{"method":"CoreSv1.Ping","params":[{}],"id":1}' "http://${CGR_HTTP_ADDR}/jsonrpc" 2>/dev/null || true)"
if [[ "$pong" == *Pong* ]]; then log_ok "CGRateS answered CoreSv1.Ping"
else log_warn "CGRateS ping response unexpected: ${pong:-<empty>}"; fi

for port in 2012 2013 2014 2080; do
  if ss -H -ltn "sport = :${port}" | awk '{print $4}' | grep -vqx "127.0.0.1:${port}"; then
    die "CGRateS port ${port} is bound to a non-loopback address!"
  fi
done

log_ok "CGRateS ${CGRATES_VERSION} running (DataDB redis/${REDIS_CGR_DB}, StorDB mysql/${DB_CGRATES})"
