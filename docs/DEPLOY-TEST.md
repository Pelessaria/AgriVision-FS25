# Deploy-Test (Backend + Datenbank)

Kurzleitfaden, um AgriVision mit MySQL/MariaDB auf einem Webserver oder lokal gegen Docker zu testen. Keine Feed-Codes oder Passwörter in dieses Dokument schreiben.

## 1. Voraussetzungen

- PHP 8.3+ mit `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`
- Composer 2
- Node 20+ (Frontend)
- Docker (optional, lokale DB) **oder** leere MySQL 8.0/8.4 bzw. MariaDB 10.11+ Datenbank beim Hoster

## 2. Lokale Datenbank (Docker)

```bash
# MySQL 8.4 auf Port 3306
docker compose --profile mysql up -d

# oder MariaDB 10.11 auf Port 3307
docker compose --profile mariadb up -d
```

Warten bis der Healthcheck grün ist, dann:

```bash
cd backend
copy .env.example .env   # Windows; auf Linux: cp .env.example .env
php artisan key:generate
# Bei MariaDB-Profil: DB_CONNECTION=mariadb, DB_PORT=3307 in .env setzen
php artisan migrate
```

## 3. Feed-Abfrage (Gameserver Web-API)

Nur serverseitig. Der Feed-Code gehört ausschließlich in `.env` oder in die verschlüsselte Workspace-Spalte — nie ins Frontend.

```bash
cd backend

# Einmalig gegen einen laufenden Dedicated Server:
php artisan fs25:feed-fetch --url="https://HOST:PORT/feed/dedicated-server-stats.json?code=…" --out=feed/last-snapshot.json

# Oder über Env:
# FS25_FEED_BASE=https://HOST:PORT
# FS25_FEED_CODE=…
php artisan fs25:feed-fetch --out=feed/last-snapshot.json
```

Optional Map-JPEG und Workspace-Update:

```bash
php artisan fs25:feed-fetch --url="…" --map=feed/map.jpg --workspace=<public_id>
```

Die Konsole und die JSON-Zusammenfassung enthalten den Feed-Code nicht.

## 4. Webserver / Shared Hosting

1. `backend/` so deployen, dass das Document Root auf `backend/public` zeigt.
2. `vendor/` per `composer install --no-dev --optimize-autoloader` erzeugen (oder Release-Artefakt).
3. `.env` auf dem Server anlegen (nicht aus dem Repo). `APP_KEY`, DB-Zugangsdaten, optional `FS25_FEED_*`.
4. `php artisan migrate --force`
5. Cron / Task Scheduler (später): `* * * * * php artisan schedule:run` — Feed-Jobs hängen dort an.
6. Frontend: `cd frontend && npm ci && npm run build` — Build nach Bedarf unter `public` oder als separates Static-Hosting ausliefern.

Rechte: `storage/` und `bootstrap/cache/` schreibbar für den PHP-User.

## 5. Connector-Mod (parallel)

Siehe `connector/PROBE.md`. ZIP: `connector/dist/FS25_AgriVisionConnector.zip`.

## 6. Smoke-Checkliste

| # | Check |
| --- | --- |
| 1 | `php artisan migrate` ohne Fehler |
| 2 | `php artisan fs25:feed-fetch …` liefert Tabelle mit `linkState=online` |
| 3 | `storage/app/feed/last-snapshot.json` ohne `code`-Feld |
| 4 | Frontend-Build lokal erreichbar |
| 5 | Connector-Probe auf dem Spielserver (hello.xml / vehicles_full) |

## 7. Sicherheit

- Feed-Code und DB-Passwort nie committen
- HTTPS zum Feed bevorzugen, wenn der Hoster es anbietet
- Keine Secrets in Issues, Logs oder Chat-Antworten
