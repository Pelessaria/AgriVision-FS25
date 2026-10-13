# Scripts

## `install.sh` — VPS / Self-Hosting

Installiert AgriVision auf einem Linux-VPS zum Testen (auch für Fortgeschrittene nutzbar).

- Kein Docker  
- Kein PowerShell  
- Kein Web-Installer  

Siehe Kommentar und `--help` in der Datei.

```bash
# Fresh download (jsDelivr avoids stale raw.githubusercontent.com cache):
curl -fsSL https://cdn.jsdelivr.net/gh/Pelessaria/AgriVision-FS25@main/scripts/install.sh -o install.sh
chmod +x install.sh
./install.sh --help

# Or from a clone:
chmod +x scripts/install.sh
./scripts/install.sh --help
```
