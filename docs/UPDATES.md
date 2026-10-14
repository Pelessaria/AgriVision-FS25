# Updates (GitHub, Shared Hosting)

**Status:** In-App-Updater + Admin-Session-Auth.  
**Stand:** 14. Oktober 2026

## Grundsatz

AgriVision läuft auf klassischem Webspace (PHP + MySQL/MariaDB). **Docker ist keine unterstützte Betriebsart.** Quelle der Wahrheit ist das öffentliche GitHub-Repository. Der Installer zieht dieses Repo (bzw. ein daraus gebautes Release-ZIP mit `vendor/`) auf den Host. Die Oberfläche kann sich danach selbst aktualisieren.

## Quelle

| Wert | Standard |
| --- | --- |
| Repository | `Pelessaria/AgriVision-FS25` |
| Zweig | `main` |
| Env | `AGRIVISION_GITHUB_OWNER`, `AGRIVISION_GITHUB_REPO`, `AGRIVISION_GITHUB_BRANCH` |

Optional: `AGRIVISION_GITHUB_TOKEN` für höhere API-Limits. Für öffentliche Repos nicht nötig.

## Was der Updater tut

1. Liest den aktuellen Commit von GitHub (`GET /repos/.../commits/{branch}`).
2. Lädt den Zipball dieses Commits herunter.
3. Überlagert den Laravel-Baum (`backend/` im Repo → Installationswurzel), **ohne** `.env`, `storage/`, `vendor/`, `public/index.php`.
4. Legt gebautes Frontend ab, falls `frontend/dist/` im Zip vorhanden ist (`public/agrivision.html` + `public/assets/`).
5. Setzt `.env`-Rechte (`0640`) und prüft, dass `APP_KEY` noch vorhanden ist.
6. Führt `php artisan migrate --force` aus.
7. Schreibt den installierten SHA nach `storage/app/update-state.json`.

## Was erhalten bleibt

- `.env` (inkl. `APP_KEY`, DB, Secrets)
- gesamtes `storage/`
- `bootstrap/cache/`
- `public/index.php` (Laravel Front-Controller)
- lokales `vendor/` (nicht aus dem Zip überschrieben — Shared Hosting ohne Composer)

## Oberfläche

Einstellungen → **Update** (nur globaler Admin, Session + CSRF):

- Status prüfen
- Update installieren

## CLI (Shell optional)

```bash
# Laravel liegt unter …/backend (nicht in der Repo-Wurzel)
cd /var/www/agrivision/backend
php artisan agrivision:update --check
php artisan agrivision:update --apply

# Wenn die Web-UI „Permission denied“ meldet (Dateien gehören root):
sudo chown -R www-data:www-data /var/www/agrivision/backend
sudo chmod 640 /var/www/agrivision/backend/.env
sudo -u www-data php artisan agrivision:update --apply
```

## API

| Methode | Pfad | Zweck |
| --- | --- | --- |
| `GET` | `/api/system/update` | Status (auth: admin) |
| `POST` | `/api/system/update` | Body `{ "confirm": true, "sha": "…" }` (auth: admin) |

## Release-ZIP vs. Repo-Zipball

| Artefakt | Inhalt | Typischer Einsatz |
| --- | --- | --- |
| Repo-Zipball | Quellcode + `frontend/dist/`, ohne `vendor/` | In-App-Update der App-Dateien |
| Release-ZIP (Installer) | App + `vendor/` + gebautes Frontend | Erstinstallation und Dependency-Wechsel |

Ändert sich `composer.lock` stark, ist ein neues Release-ZIP mit `vendor/` nötig — Composer auf dem Webspace ist nicht Voraussetzung.
