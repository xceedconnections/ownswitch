#!/usr/bin/env bash
# Privileged fail2ban helper for Ownswitch portal (run via sudo as ownswitch).
# Usage:
#   ownswitch-fail2ban status
#   ownswitch-fail2ban banned
#   ownswitch-fail2ban unban <ip>
#   ownswitch-fail2ban ban <ip>
#   ownswitch-fail2ban config
#   ownswitch-fail2ban set-config maxretry=5 findtime=10m bantime=1h
#   ownswitch-fail2ban reload
set -euo pipefail

JAIL="${OWNSWITCH_F2B_JAIL:-opensips-auth}"
SIP_JAIL_FILE=/etc/fail2ban/jail.d/ownswitch-sip.local
FILTER_FILE=/etc/fail2ban/filter.d/opensips-auth.conf
SIP_PORT="${SIP_PORT:-5060}"

die() { echo "ERR:$1" >&2; exit 1; }

need_f2b() {
  command -v fail2ban-client >/dev/null 2>&1 || die "fail2ban-client missing"
  systemctl is-active --quiet fail2ban 2>/dev/null || die "fail2ban service not active"
}

cmd_status() {
  need_f2b
  if ! fail2ban-client status "$JAIL" >/dev/null 2>&1; then
    echo "jail=${JAIL}"
    echo "enabled=0"
    echo "detail=jail not loaded"
    return 0
  fi
  local raw
  raw="$(fail2ban-client status "$JAIL" 2>/dev/null || true)"
  local currently currently_list total
  currently="$(echo "$raw" | sed -n 's/.*Currently banned:\s*//p' | head -1 | tr -d '[:space:]')"
  total="$(echo "$raw" | sed -n 's/.*Total banned:\s*//p' | head -1 | tr -d '[:space:]')"
  currently_list="$(echo "$raw" | sed -n 's/.*Banned IP list:\s*//p' | head -1 | xargs || true)"
  echo "jail=${JAIL}"
  echo "enabled=1"
  echo "currently_banned=${currently:-0}"
  echo "total_banned=${total:-0}"
  echo "banned_list=${currently_list}"
  cmd_config
}

cmd_banned() {
  need_f2b
  if ! fail2ban-client status "$JAIL" >/dev/null 2>&1; then
    return 0
  fi
  fail2ban-client status "$JAIL" 2>/dev/null \
    | sed -n 's/.*Banned IP list:\s*//p' \
    | head -1 \
    | tr ' ' '\n' \
    | sed '/^$/d'
}

cmd_unban() {
  local ip="${1:-}"
  [[ -n "$ip" ]] || die "usage: unban <ip>"
  need_f2b
  fail2ban-client set "$JAIL" unbanip "$ip" >/dev/null
  echo "OK unbanned $ip"
}

cmd_ban() {
  local ip="${1:-}"
  [[ -n "$ip" ]] || die "usage: ban <ip>"
  need_f2b
  fail2ban-client set "$JAIL" banip "$ip" >/dev/null
  echo "OK banned $ip"
}

read_kv() {
  local key="$1" file="$2" def="$3"
  if [[ -f "$file" ]]; then
    local v
    v="$(sed -n "s/^[[:space:]]*${key}[[:space:]]*=[[:space:]]*//p" "$file" | head -1 | tr -d '[:space:]')"
    [[ -n "$v" ]] && { echo "$v"; return; }
  fi
  echo "$def"
}

cmd_config() {
  local maxretry findtime bantime enabled
  maxretry="$(read_kv maxretry "$SIP_JAIL_FILE" 5)"
  findtime="$(read_kv findtime "$SIP_JAIL_FILE" 10m)"
  bantime="$(read_kv bantime "$SIP_JAIL_FILE" 1h)"
  enabled="$(read_kv enabled "$SIP_JAIL_FILE" true)"
  echo "maxretry=${maxretry}"
  echo "findtime=${findtime}"
  echo "bantime=${bantime}"
  echo "jail_enabled=${enabled}"
  echo "sip_port=${SIP_PORT}"
  echo "filter_present=$([ -f "$FILTER_FILE" ] && echo 1 || echo 0)"
  echo "jail_file_present=$([ -f "$SIP_JAIL_FILE" ] && echo 1 || echo 0)"
}

write_sip_jail() {
  local maxretry="$1" findtime="$2" bantime="$3" enabled="$4"
  cat >"$SIP_JAIL_FILE" <<EOF
# Managed by Ownswitch (portal Firewall / deploy)
[opensips-auth]
enabled  = ${enabled}
filter   = opensips-auth
port     = ${SIP_PORT}
protocol = udp,tcp
backend  = systemd
journalmatch = _SYSTEMD_UNIT=opensips.service
maxretry = ${maxretry}
findtime = ${findtime}
bantime  = ${bantime}
action   = %(action_)s
EOF
  chmod 0644 "$SIP_JAIL_FILE"
}

cmd_set_config() {
  local maxretry findtime bantime enabled
  maxretry="$(read_kv maxretry "$SIP_JAIL_FILE" 5)"
  findtime="$(read_kv findtime "$SIP_JAIL_FILE" 10m)"
  bantime="$(read_kv bantime "$SIP_JAIL_FILE" 1h)"
  enabled="$(read_kv enabled "$SIP_JAIL_FILE" true)"
  local arg
  for arg in "$@"; do
    case "$arg" in
      maxretry=*) maxretry="${arg#maxretry=}" ;;
      findtime=*) findtime="${arg#findtime=}" ;;
      bantime=*)  bantime="${arg#bantime=}" ;;
      enabled=*)  enabled="${arg#enabled=}" ;;
      *) die "unknown setting: $arg" ;;
    esac
  done
  [[ "$maxretry" =~ ^[0-9]+$ ]] && (( maxretry >= 1 && maxretry <= 100 )) || die "maxretry must be 1-100"
  [[ "$findtime" =~ ^[0-9]+[smhd]?$ ]] || die "invalid findtime"
  [[ "$bantime" =~ ^[0-9]+[smhd]?$ ]] || die "invalid bantime"
  case "$enabled" in true|false|1|0|yes|no) ;; *) die "enabled must be true/false" ;; esac
  [[ -f "$FILTER_FILE" ]] || die "filter missing: $FILTER_FILE (run deploy/fix-sip-firewall.sh)"
  write_sip_jail "$maxretry" "$findtime" "$bantime" "$enabled"
  fail2ban-client reload >/dev/null 2>&1 || systemctl reload fail2ban >/dev/null 2>&1 || systemctl restart fail2ban
  echo "OK config saved"
  cmd_config
}

cmd_reload() {
  need_f2b
  fail2ban-client reload >/dev/null 2>&1 || systemctl reload fail2ban
  echo "OK reloaded"
}

# Recent OpenSIPS auth-fail lines for an IP (why it was banned)
cmd_ip_log() {
  local ip="${1:-}"
  [[ -n "$ip" ]] || die "usage: ip-log <ip>"
  # Prefer journald for opensips.service
  journalctl -u opensips --no-pager -n 800 2>/dev/null \
    | grep -F "$ip" \
    | grep -iE 'auth failed|REGISTER|INVITE|Forbidden|Unauthorized' \
    | tail -n 40 || true
}

# Country / city / ISP for banned IP (used by portal Firewall page)
cmd_geo() {
  local ip="${1:-}"
  [[ -n "$ip" ]] || die "usage: geo <ip>"
  local raw country code city isp
  country=""; code=""; city=""; isp=""
  raw="$(curl -fsSL --max-time 3 "http://ip-api.com/json/${ip}?fields=status,country,countryCode,city,isp" 2>/dev/null || true)"
  if [[ -n "$raw" ]] && echo "$raw" | grep -q '"status":"success"'; then
    country="$(echo "$raw" | sed -n 's/.*"country":"\([^"]*\)".*/\1/p' | head -1)"
    code="$(echo "$raw" | sed -n 's/.*"countryCode":"\([^"]*\)".*/\1/p' | head -1)"
    city="$(echo "$raw" | sed -n 's/.*"city":"\([^"]*\)".*/\1/p' | head -1)"
    isp="$(echo "$raw" | sed -n 's/.*"isp":"\([^"]*\)".*/\1/p' | head -1)"
  fi
  if [[ -z "$country" ]]; then
    raw="$(curl -fsSL --max-time 3 "https://ipwho.is/${ip}" 2>/dev/null || true)"
    if [[ -n "$raw" ]] && echo "$raw" | grep -q '"success":true'; then
      country="$(echo "$raw" | sed -n 's/.*"country":"\([^"]*\)".*/\1/p' | head -1)"
      code="$(echo "$raw" | sed -n 's/.*"country_code":"\([^"]*\)".*/\1/p' | head -1)"
      city="$(echo "$raw" | sed -n 's/.*"city":"\([^"]*\)".*/\1/p' | head -1)"
    fi
  fi
  echo "ip=${ip}"
  echo "country=${country:-Unknown}"
  echo "countryCode=${code:-}"
  echo "city=${city:-}"
  echo "isp=${isp:-}"
}

case "${1:-}" in
  status)     shift; cmd_status "$@" ;;
  banned)     shift; cmd_banned "$@" ;;
  unban)      shift; cmd_unban "$@" ;;
  ban)        shift; cmd_ban "$@" ;;
  config)     shift; cmd_config "$@" ;;
  set-config) shift; cmd_set_config "$@" ;;
  reload)     shift; cmd_reload "$@" ;;
  ip-log)     shift; cmd_ip_log "$@" ;;
  geo)        shift; cmd_geo "$@" ;;
  *)
    echo "usage: $0 {status|banned|unban|ban|config|set-config|reload|ip-log|geo}" >&2
    exit 2
    ;;
esac
