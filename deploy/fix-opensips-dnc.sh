#!/usr/bin/env bash
# Enable DNC check in a live OpenSIPS cfg without full reinstall.
# Prefer Redis SISMEMBER; fall back to sqlops + opensips.dnc_cache.
set -Eeuo pipefail
CFG="${1:-/etc/opensips/opensips.cfg}"
[[ -f "$CFG" ]] || { echo "Missing $CFG"; exit 1; }

mod_dir="$(dirname "$(find /usr/lib -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -n1)")"
REDIS_PASS=""
[[ -s /etc/ownswitch/secrets/redis_password ]] && REDIS_PASS="$(tr -d '\n' </etc/ownswitch/secrets/redis_password)"
REDIS_DB="${REDIS_PORTAL_DB:-1}"
PW=""
[[ -s /etc/ownswitch/secrets/mysql_opensips_password ]] && PW="$(tr -d '\n' </etc/ownswitch/secrets/mysql_opensips_password)"
DB_URL="mysql://opensips:${PW}@127.0.0.1/opensips"

mysql -uroot opensips -e \
  "CREATE TABLE IF NOT EXISTS dnc_cache (
    group_id INT UNSIGNED NOT NULL,
    number_digits VARCHAR(32) NOT NULL,
    PRIMARY KEY (group_id, number_digits)
  ) ENGINE=InnoDB DEFAULT CHARSET=latin1" 2>/dev/null || true

python3 - "$CFG" "$mod_dir" "$REDIS_PASS" "$REDIS_DB" "$DB_URL" <<'PY'
import os, re, sys
cfg, mod_dir, redis_pass, redis_db, db_url = sys.argv[1:6]
text = open(cfg, encoding="utf-8", errors="replace").read()

# Ensure route(check_dnc) after check_rate_cap
if "route(check_dnc)" not in text:
    text = text.replace("route(check_rate_cap);", "route(check_rate_cap);\n\troute(check_dnc);")

redis_so = os.path.isfile(os.path.join(mod_dir, "cachedb_redis.so"))
sql_so = os.path.isfile(os.path.join(mod_dir, "sqlops.so"))

mods = ""
route = """route[check_dnc] {
	return;
}
"""

if redis_so:
    if redis_pass:
        url = f"redis://:{redis_pass}@127.0.0.1:6379/{redis_db}"
    else:
        url = f"redis://127.0.0.1:6379/{redis_db}"
    mods = f'''loadmodule "cachedb_redis.so"
modparam("cachedb_redis", "cachedb_url", "redis:dnc=>{url}")
'''
    route = r'''route[check_dnc] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(dncid) = $(avp(dr_gw_attrs){param.value,dncid});
	if ($var(dncid) == NULL || $(var(dncid){s.len}) == 0 || $(var(dncid){s.int}) <= 0) {
		return;
	}
	$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$var(dnc_key) = "ownswitch:dnc:" + $var(dncid);
	$var(dnc_hit) = NULL;
	if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_dst)", "$var(dnc_hit)") && $var(dnc_hit) != NULL && $(var(dnc_hit){s.int}) == 1) {
		xlog("L_WARN", "DNC hit group=$var(dncid) dst=$var(dnc_dst)\n");
		if ($var(dnc_tries) == NULL) {
			$var(dnc_tries) = 0;
		}
		$var(dnc_tries) = $(var(dnc_tries){s.int}) + 1;
		if ($(var(dnc_tries){s.int}) < 8 && use_next_gw()) {
			route(check_dnc);
			return;
		}
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
}
'''
elif sql_so:
    mods = f'''loadmodule "sqlops.so"
modparam("sqlops", "db_url", "dnc=>{db_url}")
'''
    route = r'''route[check_dnc] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(dncid) = $(avp(dr_gw_attrs){param.value,dncid});
	if ($var(dncid) == NULL || $(var(dncid){s.len}) == 0 || $(var(dncid){s.int}) <= 0) {
		return;
	}
	$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	if (sql_query("dnc", "select 1 from dnc_cache where group_id=$var(dncid) and number_digits='$var(dnc_dst)' limit 1") && $sql_query_num_rows > 0) {
		xlog("L_WARN", "DNC hit (sql) group=$var(dncid) dst=$var(dnc_dst)\n");
		if ($var(dnc_tries) == NULL) {
			$var(dnc_tries) = 0;
		}
		$var(dnc_tries) = $(var(dnc_tries){s.int}) + 1;
		if ($(var(dnc_tries){s.int}) < 8 && use_next_gw()) {
			route(check_dnc);
			return;
		}
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
}
'''

# Strip old DNC blocks
text = re.sub(r'(?m)^loadmodule "cachedb_redis\.so"\s*\n(?:modparam\("cachedb_redis"[^\n]*\n)*', "", text)
text = re.sub(r'(?m)^loadmodule "sqlops\.so"\s*\n(?:modparam\("sqlops"[^\n]*\n)*', "", text)
text = re.sub(r'(?ms)^route\[check_dnc\]\s*\{.*?\n\}\s*', "", text)

if mods and 'loadmodule "drouting.so"' in text:
    text = text.replace('loadmodule "drouting.so"', 'loadmodule "drouting.so"\n' + mods, 1)
elif mods:
    text = mods + "\n" + text

# Insert route before relay route
if "route[relay]" in text:
    text = text.replace("route[relay]", route + "\nroute[relay]", 1)
else:
    text += "\n" + route

open(cfg, "w", encoding="utf-8").write(text)
print("Patched", cfg, "redis" if redis_so else ("sqlops" if sql_so else "stub"))
PY

opensips -c -f "$CFG"
systemctl restart opensips
echo "DNC OpenSIPS check applied. Rebuild portal cache: php /opt/ownswitch/current/bin/sync-dnc-cache.php"
