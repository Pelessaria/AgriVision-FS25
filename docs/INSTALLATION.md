# Installation

**Status:** VPS-Testpfad über `scripts/install.sh`. Web-Installer und PowerShell sind derzeit nicht aktiv.  
**Stand:** 14. Oktober 2026

Technische Begründung: [INSTALLATION-STRATEGY.md](INSTALLATION-STRATEGY.md). Webspace: [SHARED-HOSTING.md](SHARED-HOSTING.md). Updates: [UPDATES.md](UPDATES.md).

## 1. VPS / Testserver (`install.sh`)

Voraussetzung: PHP 8.3+ mit den üblichen Laravel-Erweiterungen, `curl`, `unzip`, MySQL 8 oder MariaDB 10.11+.

```bash
# Von GitHub direkt:
curl -fsSL https://raw.githubusercontent.com/Pelessaria/AgriVision-FS25/main/scripts/install.sh -o install.sh
chmod +x install.sh
./install.sh --target /var/www/agrivision \
  --db-host 127.0.0.1 --db-name agrivision --db-user agrivision --db-password '…' \
  --app-url https://agrivision.example.com --migrate

# Oder aus einem Clone:
git clone https://github.com/Pelessaria/AgriVision-FS25.git
cd AgriVision-FS25
./scripts/install.sh --skip-download --migrate \
  --db-host 127.0.0.1 --db-name agrivision --db-user agrivision --db-password '…' \
  --app-url https://agrivision.example.com
```

Schnelltest ohne Reverse-Proxy:

```bash
cd /var/www/agrivision/backend
php artisan serve --host=0.0.0.0 --port=8000
```

Document Root in Produktion: `backend/public`. Frontend-Build liegt unter `backend/public/app/` (aus dem Repo-`frontend/dist` oder via `--build-frontend`).

## 2. Shared Hosting

Weiterhin Zielbild (Upload + späterer Web-Installer). Aktuell zum Testen den VPS-Pfad nutzen. Details: [SHARED-HOSTING.md](SHARED-HOSTING.md).

## 3. Nach der Installation

1. Workspace für einen Spielserver anlegen (UI / später API).
2. Connector-ZIP: `connector/dist/FS25_AgriVisionConnector.zip` — Probe: [connector/PROBE.md](../connector/PROBE.md).
3. Updates: Einstellungen → Update oder `php artisan agrivision:update --apply`.
