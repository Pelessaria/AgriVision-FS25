# Reverse Proxy (Nginx Proxy Manager)

**Stand:** 14. Oktober 2026

AgriVision läuft typischerweise hinter NPM: TLS endet am Proxy, die App spricht intern HTTP.

Beispiel-Domain: `https://agrivision.pelessaria.de`

## 1. App auf dem VPS

```bash
sudo ./install.sh --target /var/www/agrivision \
  --with-db --with-nginx --behind-proxy --listen-port 8080 --migrate \
  --db-password 'secret' \
  --app-url https://agrivision.pelessaria.de \
  --server-name agrivision.pelessaria.de
```

Wichtig in `backend/.env`:

```env
APP_URL=https://agrivision.pelessaria.de
TRUSTED_PROXIES=*
SESSION_SECURE_COOKIE=true
```

Danach:

```bash
cd /var/www/agrivision/backend
php artisan config:clear
php artisan optimize:clear
```

Intern lauscht Nginx (oder `artisan serve`) nur auf dem Forward-Port, z. B. `8080` — nicht öffentlich auf 443.

## 2. Proxy Host in Nginx Proxy Manager

| Feld | Wert |
| --- | --- |
| Domain Names | `agrivision.pelessaria.de` |
| Scheme | `http` |
| Forward Hostname / IP | IP oder Hostname des VPS (intern erreichbar) |
| Forward Port | `8080` (oder euer `--listen-port`) |
| Websockets Support | aus (nicht nötig) |
| Block Common Exploits | an |
| SSL | Let's Encrypt für die Domain, Force SSL an |
| HTTP/2 | an |

NPM muss `X-Forwarded-Proto`, `X-Forwarded-Host` und `X-Forwarded-For` mitschicken (Standard bei NPM).

## 3. Bereits installiert — nur Domain umstellen

```bash
cd /var/www/agrivision/backend
# in .env:
# APP_URL=https://agrivision.pelessaria.de
# TRUSTED_PROXIES=*
# SESSION_SECURE_COOKIE=true
php artisan config:clear
```

Nginx-Upstream-Port in der Site-Config auf denselben Port setzen, den NPM forwarded.
