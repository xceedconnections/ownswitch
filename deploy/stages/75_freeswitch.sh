#!/usr/bin/env bash
# Stage 75 - FreeSWITCH 1.10.x (OPTIONAL: only when FREESWITCH_ENABLED=1).
#
# SignalWire's apt repository needs a personal access token and only officially supports Debian,
# so on Ubuntu 24.04 we BUILD FROM SOURCE (tag ${FREESWITCH_VERSION}) into /usr/local/freeswitch.
# SIP profiles are moved off 5060 (OpenSIPS owns it) and RTP is moved below RTPengine's range.
# ESL (event socket) binds 127.0.0.1:8021 with a generated password.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "75_freeswitch"

if ! is_true "${FREESWITCH_ENABLED}"; then
  log_info "FREESWITCH_ENABLED=${FREESWITCH_ENABLED} - skipping FreeSWITCH"
  record_result "${STAGE_FILE}" "SKIPPED" "FREESWITCH_ENABLED=0"
  exit 0
fi

FS_PREFIX="/usr/local/freeswitch"
export FS_PREFIX
MARK="${FS_PREFIX}/.ownswitch-installed-version"

# ---------------------------------------------------------------------------
# 1. Build + install (skipped when the right version is already present)
# ---------------------------------------------------------------------------
if [[ -x "${FS_PREFIX}/bin/freeswitch" && "$(cat "${MARK}" 2>/dev/null || true)" == "${FREESWITCH_VERSION}" ]]; then
  log_ok "FreeSWITCH ${FREESWITCH_VERSION} already built"
else
  log_info "Building FreeSWITCH ${FREESWITCH_VERSION} from source (20-40 minutes)"
  apt_install build-essential cmake automake autoconf libtool libtool-bin pkg-config git \
    libssl-dev zlib1g-dev libdb-dev unixodbc-dev libncurses-dev libexpat1-dev libgdbm-dev bison \
    libtiff-dev uuid-dev libpcre3-dev libedit-dev libsqlite3-dev libcurl4-openssl-dev nasm \
    libogg-dev libspeex-dev libspeexdsp-dev libldns-dev libsndfile1-dev libopus-dev yasm \
    libjpeg-dev libpq-dev

  work="$(mktemp -d /tmp/fs-build.XXXXXX)"
  trap 'rm -rf "$work"' EXIT
  jobs_n="$(nproc)"

  # libks
  git clone --quiet --depth 1 https://github.com/signalwire/libks.git "$work/libks"
  ( cd "$work/libks" && cmake . -DCMAKE_INSTALL_PREFIX=/usr -DWITH_LIBBACKTRACE=1 >/dev/null && make -j"$jobs_n" && make install )
  # sofia-sip
  git clone --quiet --depth 1 https://github.com/freeswitch/sofia-sip.git "$work/sofia-sip"
  ( cd "$work/sofia-sip" && ./bootstrap.sh && ./configure CFLAGS="-g -ggdb" --with-pic --with-glib=no --without-doxygen --disable-stun --prefix=/usr \
      && make -j"$jobs_n" && make install )
  # spandsp (FreeSWITCH 1.10 requires this specific commit)
  git clone --quiet https://github.com/freeswitch/spandsp.git "$work/spandsp"
  ( cd "$work/spandsp" && git checkout --quiet 0d2e6ac65e0e8f53d652665a743015a88bf048d4 && ./bootstrap.sh \
      && ./configure CFLAGS="-g -ggdb" --with-pic --prefix=/usr && make -j"$jobs_n" && make install )
  # signalwire-c
  git clone --quiet --depth 1 https://github.com/signalwire/signalwire-c.git "$work/signalwire-c"
  ( cd "$work/signalwire-c" && cmake . -DCMAKE_INSTALL_PREFIX=/usr >/dev/null && make -j"$jobs_n" && make install )
  ldconfig

  # FreeSWITCH itself
  git clone --quiet --depth 1 --branch "${FREESWITCH_VERSION}" https://github.com/signalwire/freeswitch.git "$work/freeswitch"
  ( cd "$work/freeswitch" \
    && ./bootstrap.sh -j \
    && sed -i -E 's/^(applications|formats)\/mod_av/#\1\/mod_av/' modules.conf \
    && ./configure --prefix="${FS_PREFIX}" --enable-portable-binary --disable-dependency-tracking \
    && make -j"$jobs_n" && make install \
    && { [[ -d "${FS_PREFIX}/conf/vanilla" || -f "${FS_PREFIX}/conf/freeswitch.xml" ]] || make samples; } )
  [[ -x "${FS_PREFIX}/bin/freeswitch" ]] || die "FreeSWITCH build did not produce ${FS_PREFIX}/bin/freeswitch"
  printf '%s\n' "${FREESWITCH_VERSION}" >"${MARK}"
  note_change "built FreeSWITCH ${FREESWITCH_VERSION}"
  rm -rf "$work"; trap - EXIT
fi

ensure_system_user freeswitch "${FS_PREFIX}"
chown -R freeswitch:freeswitch "${FS_PREFIX}"
chmod -R o-rwx "${FS_PREFIX}"
install -d -m 0750 -o freeswitch -g freeswitch "${FS_PREFIX}/log" "${FS_PREFIX}/db"

# ---------------------------------------------------------------------------
# 2. Configuration: ports, RTP range, ESL
# ---------------------------------------------------------------------------
conf="${FS_PREFIX}/conf"
[[ -d "$conf" ]] || die "FreeSWITCH config dir missing: ${conf}"
fs_changed=0

set_var() { # file regex-old new
  local file=$1 old=$2 new=$3
  if [[ -f "$file" ]] && grep -qE "$old" "$file"; then
    sed -i -E "s|${old}|${new}|" "$file"; fs_changed=1
  fi
}
# Move FS SIP profiles away from 5060 (OpenSIPS) - they are not opened in the firewall
set_var "${conf}/vars.xml" 'internal_sip_port=[0-9]+' 'internal_sip_port=5090'
set_var "${conf}/vars.xml" 'external_sip_port=[0-9]+' 'external_sip_port=5091'
# Keep FS RTP below RTPengine's range
set_var "${conf}/autoload_configs/switch.conf.xml" 'name="rtp-start-port" value="[0-9]+"' 'name="rtp-start-port" value="16384"'
set_var "${conf}/autoload_configs/switch.conf.xml" 'name="rtp-end-port" value="[0-9]+"'   'name="rtp-end-port" value="29999"'
# Make sure the RTP-start/end params are uncommented
sed -i -E 's|<!--\s*(<param name="rtp-start-port"[^>]*/>)\s*-->|\1|; s|<!--\s*(<param name="rtp-end-port"[^>]*/>)\s*-->|\1|' \
  "${conf}/autoload_configs/switch.conf.xml" 2>/dev/null || true
# Default vanilla password must never survive
set_var "${conf}/vars.xml" 'default_password=[^"]+' "default_password=$(ensure_secret freeswitch_default_password 32)"

export FS_ESL_PASSWORD
FS_ESL_PASSWORD="$(ensure_secret freeswitch_esl_password 40)"
render_template "${TEMPLATE_DIR}/freeswitch-event_socket.conf.xml.tpl" "${conf}/autoload_configs/event_socket.conf.xml" 0640 freeswitch freeswitch
(( RENDER_CHANGED )) && fs_changed=1

render_template "${TEMPLATE_DIR}/systemd/freeswitch.service" /etc/systemd/system/freeswitch.service 0644 root root
if [[ "${RENDER_CHANGED}" == "1" ]]; then daemon_reload; fs_changed=1; fi

(( fs_changed )) && note_change "FreeSWITCH configuration updated"
svc_apply freeswitch "${fs_changed}"

# ---------------------------------------------------------------------------
# 3. Verify ESL is loopback only
# ---------------------------------------------------------------------------
wait_for_tcp 127.0.0.1 8021 60 || { journalctl -u freeswitch -n 40 --no-pager >&2 || true; die "FreeSWITCH ESL did not come up on 127.0.0.1:8021"; }
if ss -H -ltn 'sport = :8021' | awk '{print $4}' | grep -vqx '127.0.0.1:8021'; then
  die "FreeSWITCH ESL is listening on a non-loopback address!"
fi
persist_setting FREESWITCH_ENABLED 1
log_ok "FreeSWITCH ${FREESWITCH_VERSION} running; ESL 127.0.0.1:8021; SIP profiles 5090/5091 (not exposed)"
