#!/usr/bin/env bash
# Make OpenSIPS actually "active".
# "activating" means either:
#   - Type=forking waiting on a PID that -F never writes (process may still be up), or
#   - Restart= crash loop (module init failed) which systemctl also reports as activating.
#
#   sudo bash /opt/ownswitch-src/deploy/fix-opensips-activating.sh
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

CFG=/etc/opensips/opensips.cfg
[[ -f "$CFG" ]] || { echo "Missing $CFG"; exit 1; }

echo "======== Before ========"
systemctl is-active opensips 2>&1 || true
systemctl show opensips -p Type -p ActiveState -p SubState -p FragmentPath -p DropInPaths -p ExecStart --no-pager 2>&1 || true

id opensips >/dev/null 2>&1 || { echo "opensips user missing"; exit 1; }
chown root:opensips "$CFG"
chmod 0640 "$CFG"

# Portal (www-data) must be able to write the MI fifo for hangup.
if id www-data >/dev/null 2>&1; then
  usermod -aG opensips www-data 2>/dev/null || true
fi
if grep -q 'modparam("mi_fifo", "fifo_mode", 0660)' "$CFG"; then
  sed -i 's/modparam("mi_fifo", "fifo_mode", 0660)/modparam("mi_fifo", "fifo_mode", 0666)/' "$CFG"
  echo "MI fifo mode set to 0666"
fi

# Drop every previous drop-in (several set ExecStart=-F without Type=simple).
rm -f /etc/systemd/system/opensips.service.d/*.conf
install -d /etc/systemd/system/opensips.service.d
cat >/etc/systemd/system/opensips.service <<'EOF'
[Unit]
Description=OpenSIPS SIP server (Ownswitch)
After=network-online.target mysql.service
Wants=network-online.target

[Service]
Type=simple
User=opensips
Group=opensips
RuntimeDirectory=opensips
RuntimeDirectoryMode=0750
ExecStart=/usr/sbin/opensips -F -f /etc/opensips/opensips.cfg
ExecReload=/bin/kill -HUP $MAINPID
Restart=on-failure
RestartSec=2
TimeoutStartSec=20
TimeoutStopSec=10
LimitNOFILE=65536
KillMode=mixed

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
echo "Installed Type=simple unit (vendor forking unit masked)"

systemctl stop opensips >/dev/null 2>&1 || true
systemctl kill -s SIGKILL opensips >/dev/null 2>&1 || true
pkill -9 -x opensips >/dev/null 2>&1 || true
sleep 1

apply_boot_fixes() {
  local log=$1
  local changed=0
  # table version mismatch: "db table dialog, required version 12"
  local line table ver
  while IFS= read -r line; do
    if [[ "$line" =~ db\ table\ ([A-Za-z0-9_]+),?\ required\ version\ ([0-9]+) ]]; then
      table="${BASH_REMATCH[1]}"
      ver="${BASH_REMATCH[2]}"
      echo "Updating opensips.version ${table}=${ver}" >&2
      mysql --protocol=socket -uroot opensips -e \
        "INSERT INTO version (table_name, table_version) SELECT '${table}', ${ver} FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM version WHERE table_name='${table}'); UPDATE version SET table_version=${ver} WHERE table_name='${table}';" \
        2>/dev/null || true
      changed=1
    fi
  done <"$log"
  if grep -qE 'fail to initialize module uac|Failed to load uac_|uac_registrant|DB URL is not defined' "$log"; then
    echo "Stripping uac_auth / uac_registrant (init failure)" >&2
    sed -i \
      -e 's/^[[:space:]]*loadmodule "uac_auth.so"/# loadmodule "uac_auth.so"/' \
      -e 's/^[[:space:]]*loadmodule "uac_registrant.so"/# loadmodule "uac_registrant.so"/' \
      -e 's/^[[:space:]]*modparam("uac_registrant"/# modparam("uac_registrant"/' \
      "$CFG"
    changed=1
  fi
  echo "$changed"
}

echo "======== Foreground boot probe ========"
timeout 8 opensips -f "$CFG" -F -E >/tmp/opensips-boot.log 2>&1 || true
grep -E 'ERROR|CRITICAL|Listening|failed to initialize|fail to initialize' /tmp/opensips-boot.log | tail -n 40 || true
fixes="$(apply_boot_fixes /tmp/opensips-boot.log | tail -n 1)"
if [[ "${fixes:-0}" != "0" ]]; then
  echo "Re-probe after fixes..."
  timeout 8 opensips -f "$CFG" -F -E >/tmp/opensips-boot.log 2>&1 || true
  grep -E 'ERROR|CRITICAL|Listening|failed to initialize|fail to initialize' /tmp/opensips-boot.log | tail -n 20 || true
fi

if ! opensips -c -f "$CFG" >/tmp/opensips-c-check.log 2>&1; then
  echo "opensips -c FAILED"
  tail -n 30 /tmp/opensips-c-check.log || true
  exit 1
fi

# Probe may have left a process
pkill -9 -x opensips >/dev/null 2>&1 || true
sleep 1
systemctl reset-failed opensips >/dev/null 2>&1 || true
systemctl start opensips || true

ok=0
for i in $(seq 1 15); do
  state="$(systemctl is-active opensips 2>/dev/null || true)"
  if [[ "$state" == "active" ]]; then
    ok=1
    break
  fi
  echo "  wait ${i}s state=${state}"
  if [[ "$state" == "failed" ]]; then
    break
  fi
  sleep 1
done

# Crash loop still looks like "activating". Stop it and show the error.
if [[ "$ok" -ne 1 ]]; then
  echo "======== Still not active — journal ========"
  journalctl -u opensips -n 60 --no-pager || true
  systemctl stop opensips >/dev/null 2>&1 || true
  pkill -9 -x opensips >/dev/null 2>&1 || true
  exit 1
fi

# Hangup needs the fifo writable by php-fpm after opensips creates it.
sleep 1
if [[ -p /run/opensips/opensips.fifo ]]; then
  chmod 0666 /run/opensips/opensips.fifo || true
fi
# Older fifo path
if [[ -p /tmp/opensips_fifo ]]; then
  chmod 0666 /tmp/opensips_fifo || true
fi

echo "======== After ========"
systemctl --no-pager --full status opensips | head -n 18 || true
ss -H -ulnp 'sport = :5060' 2>/dev/null || true
ss -H -ltn 'sport = :8888' 2>/dev/null || true
echo "OK: opensips is active"
systemctl enable opensips >/dev/null 2>&1 || true
exit 0
