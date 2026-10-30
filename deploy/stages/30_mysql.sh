#!/usr/bin/env bash
# Stage 30 - MySQL 8.4 LTS (Oracle MySQL APT repository) + three separate databases.
#
# Install path (documented, see docs/INSTALL.md "MySQL 8.4 LTS"):
#   MYSQL_INSTALL_METHOD=apt-config (default)
#       download mysql-apt-config_<ver>_all.deb from dev.mysql.com, preseed debconf with
#       `mysql-apt-config/select-server = mysql-8.4-lts`, dpkg -i it (this is exactly the
#       interactive "mysql-apt-config" flow, made non-interactive).
#   MYSQL_INSTALL_METHOD=direct
#       write /etc/apt/sources.list.d/mysql.list pointing at repo.mysql.com (mysql-8.4-lts
#       component) with the MySQL signing key. Used automatically as fallback.
#
# Ubuntu's own `mysql-server` is 8.0 - this stage refuses to continue if anything but 8.4 results.
# root@localhost uses auth_socket: no MySQL root password exists or is stored.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "30_mysql"

MYSQL_KEYRING="/usr/share/keyrings/mysql-8.4.gpg"
MYSQL_KEY_URL="https://repo.mysql.com/RPM-GPG-KEY-mysql-2023"

mysql_version() { mysqld --version 2>/dev/null | sed -n 's/.*Ver \([0-9][0-9.]*\).*/\1/p' | head -n1; }

repo_via_apt_config() {
  local deb="/tmp/mysql-apt-config_${MYSQL_APT_CONFIG_VERSION}_all.deb"
  local url="https://dev.mysql.com/get/mysql-apt-config_${MYSQL_APT_CONFIG_VERSION}_all.deb"
  log_info "Fetching ${url}"
  download "$url" "$deb" "${MYSQL_APT_CONFIG_SHA256}"
  echo "mysql-apt-config mysql-apt-config/select-server select mysql-8.4-lts" | debconf-set-selections
  echo "mysql-apt-config mysql-apt-config/select-product select Ok"            | debconf-set-selections
  echo "mysql-apt-config mysql-apt-config/enable-repo select mysql-8.4-lts"    | debconf-set-selections 2>/dev/null || true
  DEBIAN_FRONTEND=noninteractive dpkg -i "$deb"
  rm -f "$deb"
}

repo_direct() {
  local tmp; tmp="$(mktemp)"
  download "${MYSQL_KEY_URL}" "$tmp"
  gpg --dearmor --yes -o "${MYSQL_KEYRING}" "$tmp"; rm -f "$tmp"
  cat >/etc/apt/sources.list.d/mysql.list <<EOF
# Managed by Ownswitch installer
deb [signed-by=${MYSQL_KEYRING}] http://repo.mysql.com/apt/ubuntu ${OS_CODENAME} mysql-8.4-lts
deb [signed-by=${MYSQL_KEYRING}] http://repo.mysql.com/apt/ubuntu ${OS_CODENAME} mysql-tools
EOF
}

candidate_version() { apt-cache policy mysql-server 2>/dev/null | awk '/Candidate:/ {print $2; exit}'; }

# ---------------------------------------------------------------------------
# 1. Install
# ---------------------------------------------------------------------------
if pkg_installed mysql-community-server || pkg_installed mysql-server; then
  v="$(mysql_version)"
  [[ "$v" == 8.4.* ]] || die "MySQL is installed but version is '${v:-unknown}', expected 8.4.x. Not touching existing data."
  log_ok "MySQL ${v} already installed"
else
  if [[ "${MYSQL_INSTALL_METHOD}" == "apt-config" ]]; then
    repo_via_apt_config || { log_warn "mysql-apt-config path failed, falling back to direct repo"; repo_direct; }
  else
    repo_direct
  fi
  apt_update
  cand="$(candidate_version)"
  if [[ "$cand" != 8.4.* ]]; then
    log_warn "apt candidate for mysql-server is '${cand}', retrying with direct repository definition"
    rm -f /etc/apt/sources.list.d/mysql-apt-config.list /etc/apt/sources.list.d/mysql.list
    repo_direct; apt_update; cand="$(candidate_version)"
  fi
  [[ "$cand" == 8.4.* ]] || die "MySQL 8.4 LTS not available from apt (candidate='${cand}'). Check network / repo.mysql.com."
  # Empty root password => root@localhost authenticates via auth_socket.
  echo "mysql-community-server mysql-community-server/root-pass password"    | debconf-set-selections
  echo "mysql-community-server mysql-community-server/re-root-pass password" | debconf-set-selections
  apt_install mysql-server mysql-client
fi

v="$(mysql_version)"
[[ "$v" == 8.4.* ]] || die "Unexpected MySQL version '${v}' after install"

# ---------------------------------------------------------------------------
# 2. Configuration
# ---------------------------------------------------------------------------
ram_mb="$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)"
pool_mb=$(( ram_mb * MYSQL_BUFFER_POOL_PCT / 100 ))
(( pool_mb >= 256 )) || pool_mb=256
export MYSQL_BUFFER_POOL_MB="$pool_mb"
if [[ "${MYSQL_OPENSIPS_AUTH_PLUGIN}" == "mysql_native_password" ]]; then
  export MYSQL_NATIVE_PASSWORD_SWITCH="ON"
else
  export MYSQL_NATIVE_PASSWORD_SWITCH="OFF"
fi

render_template "${TEMPLATE_DIR}/mysql-ownswitch.cnf.tpl" /etc/mysql/mysql.conf.d/99-ownswitch.cnf 0644 root root
svc_apply mysql "${RENDER_CHANGED}"

# ---------------------------------------------------------------------------
# 3. Wait + sanity
# ---------------------------------------------------------------------------
for _ in $(seq 1 30); do mysql_root -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
mysql_root -e 'SELECT 1' >/dev/null 2>&1 \
  || die "Cannot connect as root via auth_socket. If a previous install set a root password, run stage 30 manually after restoring socket auth."

# ---------------------------------------------------------------------------
# 4. Databases, users, grants (passwords generated once, re-synced on every run)
# ---------------------------------------------------------------------------
pw_ownswitch="$(ensure_secret mysql_ownswitch_password 40)"
pw_opensips="$(ensure_secret mysql_opensips_password 40)"
pw_cgrates="$(ensure_secret mysql_cgrates_password 40)"
pw_cgrates_ro="$(ensure_secret mysql_cgrates_ro_password 40)"

create_db() { mysql_root -e "CREATE DATABASE IF NOT EXISTS \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"; }

create_user() { # user password plugin db privileges  [extra_db_grant]
  local u=$1 pw=$2 plugin=$3 db=$4 privs=$5 host
  for host in localhost 127.0.0.1; do
    mysql_root <<SQL
CREATE USER IF NOT EXISTS '${u}'@'${host}' IDENTIFIED WITH ${plugin} BY '${pw}';
ALTER USER '${u}'@'${host}' IDENTIFIED WITH ${plugin} BY '${pw}';
GRANT ${privs} ON \`${db}\`.* TO '${u}'@'${host}';
SQL
  done
}

for d in "${DB_OWNSWITCH}" "${DB_OPENSIPS}" "${DB_CGRATES}"; do create_db "$d"; done

# Portal: full rights on its own DB only.
create_user ownswitch "$pw_ownswitch" caching_sha2_password "${DB_OWNSWITCH}" "ALL PRIVILEGES"
# OpenSIPS: its own schema only. The portal provisioner reuses this account (see ownswitch.env).
create_user opensips "$pw_opensips" "${MYSQL_OPENSIPS_AUTH_PLUGIN}" "${DB_OPENSIPS}" "ALL PRIVILEGES"
# CGRateS: owns the StorDB (needs DDL for cgr-migrator).
create_user cgrates "$pw_cgrates" caching_sha2_password "${DB_CGRATES}" "ALL PRIVILEGES"
# Portal read-only window into CGRateS StorDB (reconciliation).
create_user cgrates_ro "$pw_cgrates_ro" caching_sha2_password "${DB_CGRATES}" "SELECT"
mysql_root -e 'FLUSH PRIVILEGES'

# Hygiene: no anonymous users, no remote root, no test DB (fresh community installs have none; verify).
mysql_root -e "DELETE FROM mysql.user WHERE User='' ; DROP DATABASE IF EXISTS test; FLUSH PRIVILEGES" 2>/dev/null || true
remote_root="$(mysql_scalar "SELECT COUNT(*) FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1')")"
(( remote_root == 0 )) || log_warn "root has ${remote_root} non-local host entries in mysql.user - review manually"

# Verify listener is loopback-only
if ss -H -ltn 'sport = :3306' | awk '{print $4}' | grep -vqE '^(127\.0\.0\.1|\[::1\]):3306$'; then
  die "MySQL is listening on a non-loopback address! Check /etc/mysql config."
fi

log_ok "MySQL ${v} ready: databases ${DB_OWNSWITCH}, ${DB_OPENSIPS}, ${DB_CGRATES}"
