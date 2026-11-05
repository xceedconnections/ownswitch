#!/usr/bin/env bash
# Fix OpenSIPS stuck in systemd "activating" (health: FAIL | service opensips | activating).
# Root cause: package unit Type=forking + PIDFile, but a drop-in set ExecStart=-F (foreground)
# without Type=simple — systemd waits forever for a fork that never happens.
#
#   sudo bash /opt/ownswitch-src/deploy/fix-opensips-activating.sh
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

CFG=/etc/opensips/opensips.cfg
[[ -f "$CFG" ]] || { echo "Missing $CFG — run stage 50 first"; exit 1; }

echo "======== Before ========"
systemctl is-active opensips 2>&1 || true
systemctl --no-pager --full status opensips 2>&1 | head -n 25 || true

if [[ -f /etc/default/opensips ]] && grep -qE '^RUN_OPENSIPS=no' /etc/default/opensips; then
  sed -i 's/^RUN_OPENSIPS=no/RUN_OPENSIPS=yes/' /etc/default/opensips
  echo "Set RUN_OPENSIPS=yes in /etc/default/opensips"
fi

install -d /etc/systemd/system/opensips.service.d
cat >/etc/systemd/system/opensips.service.d/ownswitch.conf <<EOF
[Service]
Type=simple
ExecStart=
ExecStart=/usr/sbin/opensips -F -f ${CFG}
Restart=on-failure
RestartSec=2
TimeoutStartSec=30
EOF
echo "Wrote /etc/systemd/system/opensips.service.d/ownswitch.conf (Type=simple + -F)"
systemctl daemon-reload

echo "======== opensips -c ========"
if ! opensips -c -f "$CFG" 2>&1; then
  echo "Config syntax failed — running module-init recovery..."
  bash "$(dirname "$0")/fix-opensips-module-init.sh" || true
fi

systemctl stop opensips >/dev/null 2>&1 || true
pkill -9 -x opensips >/dev/null 2>&1 || true
fuser -k 5060/udp >/dev/null 2>&1 || true
fuser -k 5060/tcp >/dev/null 2>&1 || true
sleep 1

systemctl reset-failed opensips >/dev/null 2>&1 || true
systemctl start opensips || true

ok=0
for i in $(seq 1 25); do
  if systemctl is-active --quiet opensips; then
    ok=1
    break
  fi
  state="$(systemctl is-active opensips 2>/dev/null || true)"
  echo "  wait ${i}s state=${state}"
  if [[ "$state" == "activating" && "$i" -eq 12 ]]; then
    systemctl kill -s SIGKILL opensips >/dev/null 2>&1 || true
    pkill -9 -x opensips >/dev/null 2>&1 || true
    sleep 1
    systemctl reset-failed opensips >/dev/null 2>&1 || true
    systemctl start opensips || true
  fi
  sleep 1
done

echo "======== After ========"
systemctl --no-pager --full status opensips | head -n 25 || true
ss -H -ulnp 'sport = :5060' 2>/dev/null || ss -ulnp | grep 5060 || true
ss -H -ltn 'sport = :8888' 2>/dev/null || true

if [[ "$ok" -eq 1 ]]; then
  echo "OK: opensips is active"
  systemctl enable opensips >/dev/null 2>&1 || true
  exit 0
fi

echo "FAILED — trying module-init recovery..."
bash "$(dirname "$0")/fix-opensips-module-init.sh"
systemctl is-active --quiet opensips
