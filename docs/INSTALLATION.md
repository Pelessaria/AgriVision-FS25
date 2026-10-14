# Installation

**Status:** VPS über `scripts/setup-vps.sh` (inkl. Pakete, optional MariaDB/Nginx, NPM).  
**Stand:** 14. Oktober 2026

Updates: [UPDATES.md](UPDATES.md). Reverse Proxy: [REVERSE-PROXY.md](REVERSE-PROXY.md).

## VPS hinter Nginx Proxy Manager

Beispiel-Domain: `https://agrivision.pelessaria.de`

```bash
curl -fsSL https://raw.githubusercontent.com/Pelessaria/AgriVision-FS25/main/scripts/setup-vps.sh -o setup-vps.sh
chmod +x setup-vps.sh
sudo ./setup-vps.sh --target /var/www/agrivision \
  --with-db --with-nginx --behind-proxy --listen-port 8080 --migrate \
  --db-password 'secret' \
  --app-url https://agrivision.pelessaria.de \
  --server-name agrivision.pelessaria.de
```

NPM Proxy Host: `http://VPS_IP:8080`, SSL + Force SSL für die Domain.

> Nicht `cdn.jsdelivr.net/.../install.sh` verwenden — dort kann eine veraltete Datei ohne `--with-db` gecacht sein. Kanonisch ist `setup-vps.sh` über raw.githubusercontent.com.

## Ohne Proxy (direkt)

```bash
sudo ./setup-vps.sh --target /var/www/agrivision --with-db --migrate \
  --db-password 'secret'
cd /var/www/agrivision/backend && php artisan serve --host=0.0.0.0 --port=8000
```

## Aus Clone

```bash
git clone https://github.com/Pelessaria/AgriVision-FS25.git
cd AgriVision-FS25
sudo ./scripts/setup-vps.sh --skip-download --with-db --with-nginx --behind-proxy --migrate \
  --db-password 'secret' \
  --app-url https://agrivision.pelessaria.de
```

`sudo` braucht es für Pakete, MariaDB und Nginx. Nur App ohne Systempakete: `--skip-packages`.
