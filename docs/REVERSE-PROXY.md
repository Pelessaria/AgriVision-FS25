# Reverse Proxy (Nginx Proxy Manager)

**Stand:** 14. Oktober 2026

Beispiel: `https://agrivision.pelessaria.de`

## 1. App auf dem VPS

```bash
curl -fsSL https://raw.githubusercontent.com/Pelessaria/AgriVision-FS25/main/scripts/setup-vps.sh -o setup-vps.sh
chmod +x setup-vps.sh
sudo ./setup-vps.sh --target /var/www/agrivision \
  --with-db --with-nginx --behind-proxy --listen-port 8080 --migrate \
  --db-password 'secret' \
  --app-url https://agrivision.pelessaria.de \
  --server-name agrivision.pelessaria.de
```

In `backend/.env` muss stehen:

```env
APP_URL=https://agrivision.pelessaria.de
TRUSTED_PROXIES=*
SESSION_SECURE_COOKIE=true
```

```bash
cd /var/www/agrivision/backend
php artisan config:clear
```

Intern: Nginx auf Port `8080` (nicht oeffentlich 443).

## 2. Proxy Host in NPM

| Feld | Wert |
| --- | --- |
| Domain Names | `agrivision.pelessaria.de` |
| Scheme | `http` |
| Forward Hostname / IP | VPS (intern erreichbar) |
| Forward Port | `8080` |
| SSL | Let's Encrypt, Force SSL an |

## 3. Bereits installiert — nur Domain

`.env` wie oben anpassen, `php artisan config:clear`, Nginx-Listen-Port = NPM-Forward-Port.
