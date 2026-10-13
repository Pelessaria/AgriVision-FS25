# Entwicklung

**Status:** Der Phase-2-Prototyp läuft lokal. PHP, Composer und die Datenbanken kommen mit Phase 3.  
**Stand:** 12. Oktober 2026

## 1. Prototyp jetzt

Im Ordner `frontend/`:

- `npm install`
- `npm run dev` startet Vite
- `npm run lint` ist oxlint
- `npm run build` ist `tsc -b` und danach der Produktionsbuild nach `frontend/dist`

Node liegt nur auf dem Entwicklerrechner. Der Prototyp braucht kein PHP, kein Redis und keine Datenbank. Demo-Zustand bleibt im `sessionStorage`. Kopplungscodes werden nicht gespeichert.

## 2. Lokale Umgebung, sobald das Laravel-Projekt angelegt wird

- PHP 8.3 mit den Erweiterungen aus [SHARED-HOSTING.md](SHARED-HOSTING.md)
- Composer nur auf dem Entwicklerrechner
- Node.js nur auf dem Entwicklerrechner, für Vite
- MySQL 8 und MariaDB 10.11, am einfachsten als zwei Compose-Dienste für Tests
- kein Redis

`.env` bleibt lokal. `APP_DEBUG=true` nur lokal. Demo-Daten tragen `data_mode=demo`.

## 3. Geplante Befehle

Die genauen Skripte entstehen mit dem Projekt. Die Absicht:

- `composer test` führt Unit- und Feature-Tests aus
- ein CI-Job startet denselben Lauf gegen MySQL und gegen MariaDB
- `npm run build` erzeugt `public/build`
- `npm run lint` prüft das Frontend
- PHP-Stil über das Laravel-übliche Pint, sobald es im Projekt liegt

Der Shared-Hosting-ZIP wird per Skript gebaut und enthält `vendor/` und `public/build`.

## 4. Tests, die mit dem ersten Code entstehen

- Richtlinien für Rollen und für zwei Workspaces, einschließlich eines Benutzers, der nur Mitglied in einem von beiden ist
- Installer-Sperre und ein zweiter paralleler Installationsversuch
- Migrationslauf auf beiden Datenbanken
- Rechnungszustand: Timeout wird `unknown`, Bestätigung mit passender Auftrags-ID wird `paid`, eine zweite Bestätigung ändert nichts
- Snapshot ohne Balance bleibt `unavailable`
- Demo-Workspace erscheint nicht in einer Live-Liste
- Login-Rate-Limit und Session-Cookie-Flags

Ein Mock-Connector ist ein Testadapter mit festem `data_mode=demo`. Er wird nicht als Fallback für einen ausgefallenen echten Server verwendet.

## 5. Code

- Fachlogik in Modulen aus [ARCHITECTURE.md](ARCHITECTURE.md)
- keine Produktnamen im Quelltext außer dem konfigurierbaren `APP_NAME`-Standard
- keine Zugangsdaten im Git
- Sortier- und Filterfelder als Whitelist
- Übersetzungen in beiden Sprachen im selben Änderungssatz

## 6. CI, geplant

Ein Workflow: PHP-Lint und Tests, Frontend-Lint und Build, beide Datenbanken als Dienste, Prüfung dass das Release-Verzeichnis keine `.env` enthält. Hosting-Eigenschaften von GPORTAL oder Nitrado lassen sich dort nicht automatisch nachstellen; die bleiben manuelle Protokolle in [HOSTING-COMPATIBILITY.md](HOSTING-COMPATIBILITY.md).
