#!/usr/bin/env bash
# Stage 20 - ufw firewall (default deny) + optional fail2ban for SSH.
# Internal services (MySQL, Redis, CGRateS, FreeSWITCH ESL, OpenSIPS MI, RTPengine NG)
# are bound to 127.0.0.1 AND explicitly denied here (defence in depth).
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "20_firewall"

apt_install ufw
if is_true "${FAIL2BAN_ENABLED}"; then apt_install fail2ban; fi

csv_each() { # csv -> one item per line
  local IFS=','; local x
  for x in $1; do x="${x//[[:space:]]/}"; [[ -n "$x" ]] && printf '%s\n' "$x"; done
  return 0
}

ufw_rule() { # args to `ufw` (idempotent; ufw skips existing rules)
  ufw "$@" >/dev/null
}

# 1. Defaults (do not enable yet - SSH must be allowed first to avoid lock-out)
ufw default deny incoming  >/dev/null
ufw default allow outgoing >/dev/null
ufw default deny routed    >/dev/null || true
ufw logging low            >/dev/null

# 1b. Explicit denies for internal-only services. They are added BEFORE any allow rule on purpose:
#     ufw evaluates rules in order, so e.g. 33060 would otherwise be covered by the RTP allow range.
declare -A INTERNAL_PORTS=(
  [3306]="mysql" [33060]="mysqlx" [6379]="redis"
  [2012]="cgrates-jsonrpc" [2013]="cgrates-gob" [2014]="cgrates-bijson" [2080]="cgrates-http"
  [8021]="freeswitch-esl" [8888]="opensips-mi-http" [8081]="ownswitch-internal"
  ["${RTPENGINE_NG_PORT}"]="rtpengine-ng" [5090]="freeswitch-sip-int" [5091]="freeswitch-sip-ext"
)
for port in "${!INTERNAL_PORTS[@]}"; do
  ufw_rule deny in to any port "$port" comment "internal-only:${INTERNAL_PORTS[$port]}"
done

# 2. SSH
if [[ -n "${SSH_ALLOW_CIDRS}" ]]; then
  while read -r cidr; do
    ufw_rule allow from "$cidr" to any port "${SSH_PORT}" proto tcp comment 'ssh-allowlist'
  done < <(csv_each "${SSH_ALLOW_CIDRS}")
  # Never lock out the admin who is running this installer right now.
  if [[ -n "${SSH_CLIENT:-}" ]]; then
    client_ip="${SSH_CLIENT%% *}"
    ufw_rule allow from "${client_ip}" to any port "${SSH_PORT}" proto tcp comment 'ssh-current-session'
    log_warn "Added current SSH client ${client_ip} to the allow-list (remove later with: ufw status numbered)"
  fi
else
  ufw_rule limit "${SSH_PORT}/tcp" comment 'ssh'
fi

# 3. Web portal
ufw_rule allow 80/tcp  comment 'http (redirect + ACME)'
ufw_rule allow 443/tcp comment 'https portal'

# 4. SIP signalling
if [[ -n "${SIP_ALLOW_CIDRS}" ]]; then
  while read -r cidr; do
    ufw_rule allow from "$cidr" to any port "${SIP_PORT}" proto udp comment 'sip-allowlist'
    ufw_rule allow from "$cidr" to any port "${SIP_PORT}" proto tcp comment 'sip-allowlist'
  done < <(csv_each "${SIP_ALLOW_CIDRS}")
else
  ufw_rule allow "${SIP_PORT}/udp" comment 'sip udp'
  ufw_rule allow "${SIP_PORT}/tcp" comment 'sip tcp'
  log_warn "SIP is open to the world. Restrict with SIP_ALLOW_CIDRS=a.b.c.d/32,... once customer/carrier IPs are known."
fi

# 5. RTP media (RTPengine)
ufw_rule allow "${RTP_PORT_MIN}:${RTP_PORT_MAX}/udp" comment 'rtpengine media'

# 7. Enable
ufw_state="$(ufw status)"
if [[ "$ufw_state" != *"Status: active"* ]]; then
  ufw --force enable >/dev/null
  note_change "ufw enabled"
fi
systemctl enable ufw >/dev/null 2>&1 || true

# 8. fail2ban (sshd jail only; OpenSIPS flood protection is handled in SIP config + ufw allow-lists)
if is_true "${FAIL2BAN_ENABLED}"; then
  jail_tmp="$(mktemp)"
  cat >"$jail_tmp" <<EOF
# Managed by Ownswitch installer
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5
backend  = systemd

[sshd]
enabled = true
port    = ${SSH_PORT}
EOF
  jail_changed=0
  if ! cmp -s "$jail_tmp" /etc/fail2ban/jail.d/ownswitch.local 2>/dev/null; then
    install -m 0644 "$jail_tmp" /etc/fail2ban/jail.d/ownswitch.local
    jail_changed=1; note_change "wrote /etc/fail2ban/jail.d/ownswitch.local"
  fi
  rm -f "$jail_tmp"
  svc_apply fail2ban "$jail_changed"
fi

log_ok "Firewall configured"
ufw status numbered | sed 's/^/    /' || true
