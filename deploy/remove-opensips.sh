#!/usr/bin/env bash
# Remove OpenSIPS from a host that already has it.
# Does not drop the ownswitch database, and does not drop the unused opensips schema.
# FreeSWITCH must be the process on UDP 5060 before phones register again.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

log_warn "Stopping OpenSIPS. SIP on ${SIP_PORT:-5060} will drop until FreeSWITCH owns it."
systemctl disable --now opensips 2>/dev/null || true
systemctl reset-failed opensips 2>/dev/null || true

rm -f /etc/systemd/system/opensips.service
rm -rf /etc/systemd/system/opensips.service.d
systemctl daemon-reload || true

if dpkg -l 'opensips*' 2>/dev/null | grep -q '^ii'; then
  DEBIAN_FRONTEND=noninteractive apt-get purge -y 'opensips*' || true
  DEBIAN_FRONTEND=noninteractive apt-get autoremove -y || true
fi

rm -rf /etc/opensips
rm -rf /run/opensips
rm -f /etc/apparmor.d/usr.sbin.opensips /etc/apparmor.d/local/usr.sbin.opensips 2>/dev/null || true

# The portal database is required. Do not drop it. The opensips schema is unused and is left in place.
if systemctl is-active --quiet freeswitch; then
  log_ok "OpenSIPS removed. FreeSWITCH is active."
else
  log_warn "OpenSIPS removed, but freeswitch is not active. Run: sudo bash ${DEPLOY_DIR}/install.sh --only 75"
fi

if ss -H -ulnp 'sport = :5060' 2>/dev/null | grep -q freeswitch; then
  log_ok "UDP 5060 is FreeSWITCH"
else
  log_warn "UDP 5060 is not FreeSWITCH yet. Phones cannot register until it is."
fi

echo "Phones must register again. SIP usernames and passwords are unchanged."
echo "Then: sudo bash ${DEPLOY_DIR}/install.sh --only 99"
