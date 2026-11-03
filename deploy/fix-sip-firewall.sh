#!/usr/bin/env bash
# Install OpenSIPS fail2ban jail + portal helper + INVITE auth-fail log line.
#
#   cd /opt/ownswitch-src && git fetch origin main && git reset --hard origin/main \
#     && bash deploy/fix-sip-firewall.sh
#
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

SRC=/opt/ownswitch-src
CFG=/etc/opensips/opensips.cfg
SIP_PORT="${SIP_PORT:-5060}"
cd "$SRC"
git fetch origin main
git reset --hard origin/main

apt-get install -y fail2ban >/dev/null

install -m 0644 "$SRC/deploy/templates/fail2ban/opensips-auth.conf" /etc/fail2ban/filter.d/opensips-auth.conf
install -m 0755 "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sh" /usr/local/sbin/ownswitch-fail2ban
install -m 0440 "$SRC/deploy/templates/fail2ban/ownswitch-fail2ban.sudoers" /etc/sudoers.d/ownswitch-fail2ban
visudo -cf /etc/sudoers.d/ownswitch-fail2ban

if [[ ! -f /etc/fail2ban/jail.d/ownswitch-sip.local ]]; then
  cat >/etc/fail2ban/jail.d/ownswitch-sip.local <<EOF
# Managed by Ownswitch
[opensips-auth]
enabled  = true
filter   = opensips-auth
port     = ${SIP_PORT}
protocol = udp,tcp
backend  = systemd
journalmatch = _SYSTEMD_UNIT=opensips.service
maxretry = 5
findtime = 10m
bantime  = 1h
action   = %(action_)s
EOF
  chmod 0644 /etc/fail2ban/jail.d/ownswitch-sip.local
fi

# Ensure SSH jail defaults file exists (do not wipe)
if [[ ! -f /etc/fail2ban/jail.d/ownswitch.local ]]; then
  cat >/etc/fail2ban/jail.d/ownswitch.local <<EOF
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5
backend  = systemd

[sshd]
enabled = true
EOF
fi

# Patch live opensips.cfg: INVITE auth failed log (if missing)
chattr -i "$CFG" 2>/dev/null || true
python3 - "$CFG" <<'PY'
import sys
path = sys.argv[1]
text = open(path, encoding="utf-8", errors="replace").read()
needle = 'INVITE auth failed for'
if needle in text:
    print("OK invite auth log already present")
else:
    old = 'if (!proxy_authorize("", "subscriber")) {\n\t\t\tproxy_challenge("", "auth");'
    new = 'if (!proxy_authorize("", "subscriber")) {\n\t\t\txlog("L_WARN", "INVITE auth failed for $fU src=$si\\n");\n\t\t\tproxy_challenge("", "auth");'
    if old in text:
        text = text.replace(old, new, 1)
        open(path, "w", encoding="utf-8").write(text)
        print("OK patched INVITE auth fail xlog")
    else:
        # looser match
        import re
        pat = re.compile(
            r'(if\s*\(\s*!proxy_authorize\(\s*""\s*,\s*"subscriber"\s*\)\s*\)\s*\{\s*)\n(\s*)(proxy_challenge)',
            re.M,
        )
        m = pat.search(text)
        if not m:
            print("WARN: could not patch INVITE auth log — edit opensips.cfg manually")
        else:
            text = pat.sub(
                r'\1\n\2xlog("L_WARN", "INVITE auth failed for $fU src=$si\\n");\n\2\3',
                text,
                count=1,
            )
            open(path, "w", encoding="utf-8").write(text)
            print("OK patched INVITE auth fail xlog (regex)")
PY

if opensips -c -f "$CFG" >/dev/null 2>&1; then
  systemctl restart opensips
else
  echo "WARN: opensips -c failed; not restarting opensips — fix cfg first"
  opensips -c -f "$CFG" || true
fi

systemctl enable fail2ban >/dev/null 2>&1 || true
systemctl restart fail2ban
sleep 1
fail2ban-client status opensips-auth || true

bash "$SRC/deploy/refresh-ui.sh"

echo
echo "################################################################"
echo " SIP firewall: fail2ban jail opensips-auth (5 fails / 10m / 1h)."
echo " Portal: Settings → Firewall  (unban / thresholds / manual ban)."
echo " Helper: /usr/local/sbin/ownswitch-fail2ban status"
echo "################################################################"
