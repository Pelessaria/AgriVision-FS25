# Installation

**Status:** VPS-Testpfad über `scripts/install.sh` (inkl. Paket-Provisionierung).  
**Stand:** 14. Oktober 2026

Updates: [UPDATES.md](UPDATES.md). Shared Hosting später: [SHARED-HOSTING.md](SHARED-HOSTING.md).

## VPS / Testserver

Das Script installiert bei Bedarf per `apt`/`dnf`:

- PHP 8.3+ (cli, fpm) inkl. Laravel-Erweiterungen  
- curl, unzip, git  
- optional MariaDB (`--with-db`) inkl. DB + User  
- optional Nginx (`--with-nginx`) inkl. Site-Config auf `backend/public`

Hinter **Nginx Proxy Manager** (Beispiel `agrivision.pelessaria.de`):

```bash
curl -fsSL https://cdn.jsdelivr.net/gh/Pelessaria/AgriVision-FS25@main/scripts/install.sh -o install.sh
chmod +x install.sh
sudo ./install.sh --target /var/www/agrivision \
  --with-db --with-nginx --behind-proxy --listen-port 8080 --migrate \
  --db-password 'secret' \
  --app-url https://agrivision.pelessaria.de \
  --server-name agrivision.pelessaria.de
```

NPM: Proxy Host → `http://VPS_IP:8080`, SSL/Force SSL für die Domain. Details: [REVERSE-PROXY.md](REVERSE-PROXY.md).

Ohne Nginx (nur PHP built-in server):

```bash
sudo ./install.sh --target /var/www/agrivision --with-db --migrate \
  --db-password 'secret'
cd /var/www/agrivision/backend && php artisan serve --host=0.0.0.0 --port=8000
```

Aus einem Clone:

```bash
git clone https://github.com/Pelessaria/AgriVision-FS25.git
cd AgriVision-FS25
sudo ./scripts/install.sh --skip-download --with-db --with-nginx --migrate \
  --db-password 'secret'
```

`sudo` ist nötig für Pakete, MariaDB und Nginx. Nur App-Code ohne Systempakete: `--skip-packages`.

## Danach

1. UI unter `APP_URL` bzw. `…/app/` (gebauter Frontend-Stand).  
2. Connector: `connector/dist/FS25_AgriVisionConnector.zip` — [PROBE.md](../connector/PROBE.md).  
3. Updates: `php artisan agrivision:update --apply` oder Einstellungen → Update.
