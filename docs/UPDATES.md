# Updates (GitHub, Shared Hosting)

**Status:** In-App-Updater und CLI sind implementiert. Realm-Admin-Auth am Endpoint folgt mit dem Installer.  
**Stand:** 14. Oktober 2026

## Grundsatz

AgriVision läuft auf klassischem Webspace (PHP + MySQL/MariaDB). **Docker ist keine unterstützte Betriebsart.** Quelle der Wahrheit ist das öffentliche GitHub-Repository. Der spätere Installer zieht dieses Repo (bzw. ein daraus gebautes Release-ZIP mit `vendor/`) auf den Webspace. Die Oberfläche kann sich danach selbst aktualisieren.

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
3. Überlagert den Laravel-Baum (`backend/` im Repo → Installationswurzel).
4. Legt gebautes Frontend ab, falls `frontend/dist/` im Zip vorhanden ist (`public/app/`).
5. Führt `php artisan migrate --force` aus.
6. Schreibt den installierten SHA nach `storage/app/update-state.json`.

## Was erhalten bleibt

- `.env`
- gesamtes `storage/`
- `bootstrap/cache/` (wird danach geleert, wo möglich)
- lokales `vendor/` (nicht aus dem Zip überschrieben — Shared Hosting ohne Composer)

## Oberfläche

Einstellungen → **Update** (nur Administrator):

- Status prüfen (Backend-API oder direkt GitHub im Prototyp)
- Update installieren (nur wenn `/api/system/update` erreichbar ist)

## CLI (Shell optional)

```bash
cd backend
php artisan agrivision:update --check
php artisan agrivision:update --apply
```

## API

| Methode | Pfad | Zweck |
| --- | --- | --- |
| `GET` | `/api/system/update` | Status |
| `POST` | `/api/system/update` | Body `{ "confirm": true, "sha": "…" }` |

Ohne Realm-Login vorerst absichtlich mit `confirm=true` geschützt. Sobald Sessions stehen, nur Realm-Admin.

## Release-ZIP vs. Repo-Zipball

| Artefakt | Inhalt | Typischer Einsatz |
| --- | --- | --- |
| Repo-Zipball | Quellcode, ohne `vendor/` | In-App-Update der App-Dateien |
| Release-ZIP (Installer) | App + `vendor/` + gebautes Frontend | Erstinstallation und Dependency-Wechsel |

Ändert sich `composer.lock` stark, ist ein neues Release-ZIP mit `vendor/` nötig — Composer auf dem Webspace ist nicht Voraussetzung.
