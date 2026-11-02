#!/usr/bin/env bash
# Pull latest Ownswitch UI into a new release and switch traffic.
# Usage (on the server as root):
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/refresh-ui.sh | sudo bash
# Or:
#   sudo bash /opt/ownswitch-src/deploy/refresh-ui.sh
set -euo pipefail

SRC="${OWNSWITCH_SRC_DIR:-/opt/ownswitch-src}"
OWN_OPT="${OWN_OPT:-/opt/ownswitch}"
OWN_VAR="${OWN_VAR:-/var/lib/ownswitch}"
OWN_ETC="${OWN_ETC:-/etc/ownswitch}"
REPO_URL="${OWN_REPO_URL:-https://github.com/xceedconnections/ownswitch.git}"
BRANCH="${OWN_BRANCH:-main}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0" >&2
  exit 1
fi

echo "== Ownswitch UI refresh =="
echo "SRC=$SRC  current=$(readlink -f "$OWN_OPT/current" 2>/dev/null || echo missing)"

if [[ ! -d "$SRC/.git" ]]; then
  echo "Cloning $REPO_URL → $SRC"
  install -d -m 0755 "$(dirname "$SRC")"
  git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$SRC"
else
  echo "Pulling $BRANCH…"
  git -C "$SRC" fetch origin "$BRANCH"
  git -C "$SRC" checkout "$BRANCH"
  git -C "$SRC" reset --hard "origin/$BRANCH"
fi

test -f "$SRC/public/index.php" || { echo "FATAL: $SRC/public/index.php missing"; exit 1; }
test -f "$SRC/public/assets/css/app.css" || { echo "FATAL: $SRC/public/assets/css/app.css missing"; exit 1; }
test -f "$SRC/app/Views/layout.php" || { echo "FATAL: layout.php missing"; exit 1; }

if ! grep -q 'os-auth-page\|os-topbar\|os-brand-mark' "$SRC/app/Views/layout.php"; then
  echo "FATAL: pulled layout.php does not contain the new CRM markup — wrong commit?"
  exit 1
fi

HEAD="$(git -C "$SRC" rev-parse --short HEAD)"
echo "Source OK at $HEAD"

getent passwd ownswitch >/dev/null 2>&1 || useradd --system --home-dir "$OWN_OPT" --shell /usr/sbin/nologin --user-group ownswitch
install -d -m 0755 "$OWN_OPT/releases"
install -d -m 0750 -o ownswitch -g ownswitch "$OWN_VAR/storage/cache" "$OWN_VAR/storage/uploads" 2>/dev/null || true

REL="$OWN_OPT/releases/ui-$(date -u +%Y%m%d%H%M%S)-${HEAD}"
mkdir -p "$REL"
rsync -a --delete \
  --exclude '.git/' --exclude '.github/' --exclude 'deploy/' --exclude 'docs/' --exclude 'tests/' \
  --exclude 'node_modules/' --exclude '.env' --exclude '.env.*' --exclude '*.md' \
  --exclude '/storage/' --exclude '/var/' \
  "$SRC/" "$REL/"

test -f "$REL/public/index.php"
test -f "$REL/public/assets/css/app.css"
test -f "$REL/app/Views/layout.php"
rm -f "$REL/.ownswitch-placeholder"
ln -sfn "$OWN_VAR/storage" "$REL/storage"
chown -R root:ownswitch "$REL"
# 0751 on the release root: other needs +x to traverse into public (nginx runs as www-data).
# Do NOT chmod -R o= on $REL itself — that causes static asset 404s.
chmod 0751 "$REL"
find "$REL" -mindepth 1 -maxdepth 1 ! -name public ! -name storage -exec chmod -R u=rwX,g=rX,o= {} +
chmod -R u=rwX,go=rX "$REL/public"
chmod 0755 /opt/ownswitch /opt/ownswitch/releases 2>/dev/null || true
# Ensure nginx can read group-owned trees even if a future chmod tightens other.
if id www-data >/dev/null 2>&1; then
  usermod -aG ownswitch www-data 2>/dev/null || true
fi

ln -sfn "$REL" "$OWN_OPT/current"
echo "Switched current → $REL"

# Keep nginx document root on /opt/ownswitch/current/public
NGINX_CONF="/etc/nginx/sites-available/ownswitch.conf"
if [[ -f "$NGINX_CONF" ]]; then
  if grep -qE 'root .+/public;' "$NGINX_CONF"; then
    sed -i -E 's#root[[:space:]]+[^;]+/public;#root /opt/ownswitch/current/public;#g' "$NGINX_CONF"
    echo "Nginx root → /opt/ownswitch/current/public"
  fi
  nginx -t && systemctl reload nginx
else
  echo "WARN: $NGINX_CONF missing — skip nginx rewrite"
fi

systemctl reload php8.3-fpm 2>/dev/null || systemctl restart php8.3-fpm 2>/dev/null || true

# Persist APP_SRC
if [[ -f "$OWN_ETC/install.conf" ]]; then
  grep -v '^APP_SRC=' "$OWN_ETC/install.conf" > /tmp/ownswitch-ic.$$ || true
  echo "APP_SRC=$SRC" >> /tmp/ownswitch-ic.$$
  mv /tmp/ownswitch-ic.$$ "$OWN_ETC/install.conf"
fi

echo
echo "== Verify =="
echo "layout markers: $(grep -c 'os-auth-page\|os-topbar\|os-brand-mark' "$OWN_OPT/current/app/Views/layout.php" || true)"
echo "css bytes: $(wc -c < "$OWN_OPT/current/public/assets/css/app.css")"
ls -la "$OWN_OPT/current/public/assets/css/app.css" "$OWN_OPT/current/public/assets/js/app.js"
# Apply DB migrations (must succeed for new modules e.g. DIDs)
if [[ -f "$REL/bin/migrate.php" ]]; then
  echo "Running migrations…"
  if runuser -u ownswitch -- env OWNSWITCH_ENV_FILE="${OWN_ETC}/ownswitch.env" php "$REL/bin/migrate.php"; then
    echo "Migrations OK"
  elif env OWNSWITCH_ENV_FILE="${OWN_ETC}/ownswitch.env" php "$REL/bin/migrate.php"; then
    echo "Migrations OK (root)"
  else
    echo "FATAL: migrations failed — DID and other new tables will 500 until this succeeds" >&2
    exit 1
  fi
fi

DOMAIN="$(grep -E '^OWNSWITCH_DOMAIN=' "$OWN_ETC/install.conf" 2>/dev/null | cut -d= -f2- || true)"
DOMAIN="${DOMAIN:-voip.xceedconnections.com}"
echo
echo "Hard-refresh the browser (Ctrl+Shift+R)."
echo "Checks:"
echo "  curl -k -I https://${DOMAIN}/assets/css/app.css"
echo "  curl -k -s https://${DOMAIN}/login | grep -E 'os-auth-page|bootstrap-icons|os-brand-mark'"
echo "Done ($HEAD)."
