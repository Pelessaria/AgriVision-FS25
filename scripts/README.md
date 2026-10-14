# Scripts

## `setup-vps.sh` — VPS / Self-Hosting

Installiert AgriVision auf einem Linux-VPS (Pakete, optional MariaDB/Nginx, NPM-ready).

```bash
curl -fsSL https://raw.githubusercontent.com/Pelessaria/AgriVision-FS25/main/scripts/setup-vps.sh -o setup-vps.sh
chmod +x setup-vps.sh
sudo ./setup-vps.sh --help
```

`install.sh` ist nur noch ein Shim (CDN-Cache-Probleme mit der alten Datei).
