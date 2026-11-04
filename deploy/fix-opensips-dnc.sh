#!/usr/bin/env bash
# Enable DNC check in a live OpenSIPS cfg (OpenSIPS 3.6+ sqlops / cachedb_redis).
# SIP-user DNC blocks before trunk; trunk DNC uses original dialled digits (dlg_val os_dst).
# On hit: instant 403 — never failover to another trunk / never INVITE the vendor.
# Backs up cfg and restores if opensips -c fails.
set -Eeuo pipefail
CFG="${1:-/etc/opensips/opensips.cfg}"
[[ -f "$CFG" ]] || { echo "Missing $CFG"; exit 1; }

mod_dir="$(dirname "$(find /usr/lib -type f -name tm.so -path '*opensips/modules*' 2>/dev/null | head -n1)")"
[[ -d "$mod_dir" ]] || { echo "OpenSIPS modules dir not found"; exit 1; }

apt-get install -y opensips-module-cachedb-redis opensips-cachedb-redis-module \
  opensips-module-sqlops opensips-sqlops-module >/dev/null 2>&1 || true

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
mysql -uroot opensips -e \
  "ALTER TABLE subscriber ADD COLUMN dnc_group_id INT UNSIGNED NOT NULL DEFAULT 0" 2>/dev/null || true

BAK="${CFG}.bak-dnc-$(date +%Y%m%d%H%M%S)"
cp -a "$CFG" "$BAK"
echo "Backup: $BAK"

python3 - "$CFG" "$mod_dir" "$REDIS_PASS" "$REDIS_DB" "$DB_URL" <<'PY'
import os, re, sys
cfg, mod_dir, redis_pass, redis_db, db_url = sys.argv[1:6]
text = open(cfg, encoding="utf-8", errors="replace").read()

# load_credentials: os_dncid
lc = re.search(r'modparam\("auth_db",\s*"load_credentials",\s*"([^"]*)"\)', text)
if lc and "os_dncid" not in lc.group(1):
    creds = lc.group(1)
    if not creds.endswith(";"):
        creds += ";"
    creds += "$avp(os_dncid)=dnc_group_id"
    text = text[: lc.start(1)] + creds + text[lc.end(1) :]

# Ensure route(check_sip_dnc) before do_routing
if "route(check_sip_dnc)" not in text:
    text = text.replace(
        "if (!do_routing(1",
        "route(check_sip_dnc);\n\n\tif (!do_routing(1",
        1,
    )

# Ensure route(check_dnc) after do_routing / before strip (prefer before apply_ruri_strip)
if "route(check_dnc)" not in text:
    if "route(apply_ruri_strip);" in text:
        text = text.replace("route(apply_ruri_strip);", "route(check_dnc);\n\troute(apply_ruri_strip);", 1)
    else:
        text = text.replace("route(check_rate_cap);", "route(check_dnc);\n\troute(check_rate_cap);")

redis_so = os.path.isfile(os.path.join(mod_dir, "cachedb_redis.so"))
sql_so = os.path.isfile(os.path.join(mod_dir, "sqlops.so"))

mods = ""
if redis_so:
    url = f"redis://:{redis_pass}@127.0.0.1:6379/{redis_db}" if redis_pass else f"redis://127.0.0.1:6379/{redis_db}"
    mods = f'''loadmodule "cachedb_redis.so"
modparam("cachedb_redis", "cachedb_url", "redis:dnc=>{url}")
'''
    route = r'''route[check_sip_dnc] {
	if ($avp(os_dncid) == NULL || $(avp(os_dncid){s.int}) <= 0) {
		return;
	}
	$var(dncid) = $avp(os_dncid);
	$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$var(dnc_key) = "ownswitch:dnc:" + $var(dncid);
	$var(dnc_hit) = NULL;
	if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_dst)", "$var(dnc_hit)") && $var(dnc_hit) != NULL && $(var(dnc_hit){s.int}) == 1) {
		xlog("L_WARN", "SIP DNC hit group=$var(dncid) dst=$var(dnc_dst) — block before trunk\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$var(dnc_hit) = NULL;
		if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_alt)", "$var(dnc_hit)") && $var(dnc_hit) != NULL && $(var(dnc_hit){s.int}) == 1) {
			xlog("L_WARN", "SIP DNC hit (NANP alt) group=$var(dncid) dst=$var(dnc_dst) alt=$var(dnc_alt)\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}
}
route[check_dnc] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(dncid) = $(avp(dr_gw_attrs){param.value,dncid});
	if ($var(dncid) == NULL || $(var(dncid){s.len}) == 0 || $(var(dncid){s.int}) <= 0) {
		return;
	}
	$var(dnc_dst) = $(dlg_val(os_dst){re.subst,/[^0-9]//g});
	if ($var(dnc_dst) == NULL || $(var(dnc_dst){s.len}) < 3) {
		$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	}
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$var(dnc_key) = "ownswitch:dnc:" + $var(dncid);
	$var(dnc_hit) = NULL;
	if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_dst)", "$var(dnc_hit)") && $var(dnc_hit) != NULL && $(var(dnc_hit){s.int}) == 1) {
		xlog("L_WARN", "Trunk DNC hit group=$var(dncid) dst=$var(dnc_dst) — instant block (no vendor)\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$var(dnc_hit) = NULL;
		if (cache_raw_query("redis:dnc", "SISMEMBER $var(dnc_key) $var(dnc_alt)", "$var(dnc_hit)") && $var(dnc_hit) != NULL && $(var(dnc_hit){s.int}) == 1) {
			xlog("L_WARN", "Trunk DNC hit (NANP alt) group=$var(dncid) dst=$var(dnc_dst) alt=$var(dnc_alt)\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}
}
'''
    mode = "redis"
elif sql_so:
    mods = f'''loadmodule "sqlops.so"
modparam("sqlops", "db_url", "{db_url}")
'''
    route = r'''route[check_sip_dnc] {
	if ($avp(os_dncid) == NULL || $(avp(os_dncid){s.int}) <= 0) {
		return;
	}
	$var(dncid) = $avp(os_dncid);
	$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$var(dnc_hit) = NULL;
	if (sql_query_one("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='$var(dnc_dst)' limit 1", "$var(dnc_hit)") && $var(dnc_hit) != NULL) {
		xlog("L_WARN", "SIP DNC hit (sql) group=$var(dncid) dst=$var(dnc_dst) — block before trunk\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$var(dnc_hit) = NULL;
		if (sql_query_one("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='$var(dnc_alt)' limit 1", "$var(dnc_hit)") && $var(dnc_hit) != NULL) {
			xlog("L_WARN", "SIP DNC hit (sql NANP alt) group=$var(dncid) dst=$var(dnc_dst) alt=$var(dnc_alt)\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}
}
route[check_dnc] {
	if ($avp(dr_gw_attrs) == NULL) {
		return;
	}
	$var(dncid) = $(avp(dr_gw_attrs){param.value,dncid});
	if ($var(dncid) == NULL || $(var(dncid){s.len}) == 0 || $(var(dncid){s.int}) <= 0) {
		return;
	}
	$var(dnc_dst) = $(dlg_val(os_dst){re.subst,/[^0-9]//g});
	if ($var(dnc_dst) == NULL || $(var(dnc_dst){s.len}) < 3) {
		$var(dnc_dst) = $(rU{re.subst,/[^0-9]//g});
	}
	if ($(var(dnc_dst){s.len}) < 3) {
		return;
	}
	$var(dnc_hit) = NULL;
	if (sql_query_one("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='$var(dnc_dst)' limit 1", "$var(dnc_hit)") && $var(dnc_hit) != NULL) {
		xlog("L_WARN", "Trunk DNC hit (sql) group=$var(dncid) dst=$var(dnc_dst) — instant block (no vendor)\n");
		acc_db_request("403 Do Not Call", "acc");
		send_reply(403, "Do Not Call");
		exit;
	}
	$var(dnc_alt) = NULL;
	if ($(var(dnc_dst){s.len}) == 11 && $(var(dnc_dst){s.substr,0,1}) == "1") {
		$var(dnc_alt) = $(var(dnc_dst){s.substr,1,0});
	} else if ($(var(dnc_dst){s.len}) == 10) {
		$var(dnc_alt) = "1" + $var(dnc_dst);
	}
	if ($var(dnc_alt) != NULL && $(var(dnc_alt){s.len}) >= 3) {
		$var(dnc_hit) = NULL;
		if (sql_query_one("select 1 from dnc_cache where group_id=$var(dncid) and number_digits='$var(dnc_alt)' limit 1", "$var(dnc_hit)") && $var(dnc_hit) != NULL) {
			xlog("L_WARN", "Trunk DNC hit (sql NANP alt) group=$var(dncid) dst=$var(dnc_dst) alt=$var(dnc_alt)\n");
			acc_db_request("403 Do Not Call", "acc");
			send_reply(403, "Do Not Call");
			exit;
		}
	}
}
'''
    mode = "sqlops"
else:
    route = """route[check_sip_dnc] {
	return;
}
route[check_dnc] {
	return;
}
"""
    mode = "stub"

text = re.sub(r'(?m)^loadmodule "cachedb_redis\.so"\s*\n(?:modparam\("cachedb_redis"[^\n]*\n)*', "", text)
text = re.sub(r'(?m)^loadmodule "sqlops\.so"\s*\n(?:modparam\("sqlops"[^\n]*\n)*', "", text)
text = re.sub(r'(?ms)^route\[check_sip_dnc\]\s*\{.*?\n\}\s*', "", text)
text = re.sub(r'(?ms)^route\[check_dnc\]\s*\{.*?\n\}\s*', "", text)

if mods and 'loadmodule "drouting.so"' in text:
    text = text.replace('loadmodule "drouting.so"', 'loadmodule "drouting.so"\n' + mods, 1)
elif mods:
    text = mods + "\n" + text

if "route[relay]" in text:
    text = text.replace("route[relay]", route + "\nroute[relay]", 1)
else:
    text += "\n" + route

open(cfg, "w", encoding="utf-8").write(text)
print("Patched", cfg, mode)
PY

if ! opensips -c -f "$CFG"; then
  echo "ERROR: opensips -c failed — restoring $BAK" >&2
  cp -a "$BAK" "$CFG"
  opensips -c -f "$CFG" || true
  exit 1
fi

systemctl restart opensips
echo "DNC OpenSIPS check OK (instant 403, no vendor hit, NANP aliases)."
echo "Rebuild cache: sudo -u ownswitch env OWNSWITCH_ENV_FILE=/etc/ownswitch/ownswitch.env php /opt/ownswitch/current/bin/sync-dnc-cache.php"
echo "Re-save SIP users / trunks so dncid attrs sync."
