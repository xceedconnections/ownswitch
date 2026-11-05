# Ownswitch

Multi-tenant VoIP billing platform — **native PHP** (no Laravel/Symfony) + MySQL 8.4, OpenSIPS 3.6, CGRateS v0.10.4, RTPengine, optional FreeSWITCH.

Install target: **Ubuntu Server 24.04 LTS (amd64)** — e.g. Hetzner Cloud.

## New Ubuntu 24.04 server (OpenSIPS)

This repository is the OpenSIPS portal. On a clean Ubuntu 24.04 amd64 server, as root, point a DNS A record at the server and run:

```bash
curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/install-server.sh \
  | sudo bash -s -- \
      --domain voip.yourdomain.com \
      --email ops@yourdomain.com \
      --password 'ChooseALongPassword1!'
```

The same install after a manual clone:

```bash
sudo apt-get update && sudo apt-get install -y git
git clone https://github.com/xceedconnections/ownswitch.git /opt/ownswitch-src
cd /opt/ownswitch-src
export OWNSWITCH_DOMAIN=voip.yourdomain.com
export OWNSWITCH_ADMIN_EMAIL=ops@yourdomain.com
export OWN_SWITCH_ADMIN_PASS='ChooseALongPassword1!'
export FREESWITCH_ENABLED=0
sudo -E bash deploy/install.sh --yes --non-interactive
```

That installs MySQL, Redis, OpenSIPS 3.6 on UDP/TCP 5060, CGRateS, RTPengine, Nginx, PHP, and the portal. FreeSWITCH is not installed. The admin password is printed and saved to `/root/ownswitch-credentials.txt`. Open TCP 22, 80, 443 and UDP/TCP 5060 plus UDP 30000–40000 on the cloud firewall.

To install again on a server that already has this checkout:

```bash
cd /opt/ownswitch-src
git fetch origin
git reset --hard origin/main
sudo bash deploy/install.sh --yes --non-interactive
```

**Recommended server:** Hetzner **CX42 / CPX42** (8 vCPU, 16 GB RAM) — see [docs/HETZNER.md](docs/HETZNER.md). Lab minimum: **CX32** (4 vCPU / 8 GB). Use Ubuntu **24.04**, not ARM.

### If install failed (MySQL GPG / incomplete stack)

One command repairs MySQL APT keys, pulls latest code, and resumes from stage 30 automatically:

```bash
curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitch/main/deploy/repair-and-continue.sh \
  | sudo bash
```

## Quick start (dev / XAMPP)

```powershell
cd D:\xampp\htdocs\Ownswitch
$php = "D:\xampp\php\php.exe"
Copy-Item config\.env.example config\.env   # set DB_* and APP_KEY
& $php bin\migrate.php --create-db --seed
& $php bin\create_admin.php --email=admin@example.com --password="Str0ng-Pass-123!"
& $php tests\run.php
& $php -S localhost:8080 -t public public\index.php
```

Portal: http://localhost:8080

## Production install (Ubuntu 24.04)

See [docs/INSTALL.md](docs/INSTALL.md):

```bash
sudo -E ./deploy/install.sh --yes
```

## Documentation

| Doc | Purpose |
|---|---|
| [PLAN.md](PLAN.md) | Phased plan |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Components & API contracts |
| [COMPATIBILITY.md](COMPATIBILITY.md) | Version matrix |
| [BILLING_RULES.md](BILLING_RULES.md) | Increment formula |
| [SECURITY.md](SECURITY.md) | Security design |
| [DATABASE_DESIGN.md](DATABASE_DESIGN.md) | Schema |
| [PROGRESS.md](PROGRESS.md) | Status |
| [docs/INSTALL.md](docs/INSTALL.md) | Installer |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | Ops commands |
| [docs/ACCEPTANCE.md](docs/ACCEPTANCE.md) | Go-live checklist |
| [docs/BACKUP.md](docs/BACKUP.md) | Backup/restore |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Troubleshooting |

## Layout

- `public/` — web root only
- `app/` — controllers, services, repositories, integrations, views
- `database/migrations/` — SQL migrations
- `deploy/` — Ubuntu installer
- `bin/` — CLI tools
- `tests/` — automated suite (`php tests/run.php`)
