# Roadmap

**Status:** Phase 1 erledigt. Phase 2 ist als Prototyp umgesetzt, Prüfung in [UX.md](UX.md).  
**Stand:** 12. Oktober 2026

Jede Phase endet mit etwas Prüfbaren. Die nächste Phase beginnt nach Abnahme der vorherigen.

## Phase 1 — Analyse und Architektur

**Erledigt mit diesen Dokumenten.**

Abnahme: die Kernentscheidung ist begründet, FS25-Schnittstellen sind in Fakten, Annahmen und offene Punkte getrennt, und es wurde kein Anwendungscode angelegt.

## Phase 2 — Designprototyp

Eigenständiges Frontend mit gekennzeichneten Demo-Daten. Spezifikation: [UX.md](UX.md).

Schritte:

1. Design-Tokens und App-Rahmen: Sidebar, Header, Inhaltsfläche, Graphit-Theme.
2. Login mit Validierung, Lade- und Fehlerzustand. Die Anmeldung bleibt im Prototyp lokal und spricht noch kein echtes Konto an.
3. Control Center mit mehreren Instanzkarten: online, offline, ungekoppelt, Warnung, Demo-Kennzeichnung.
4. Suche, Filter, Sortierung, leere Liste, Ladezustand, Fehlerzustand.
5. Assistent „Instanz anlegen“ bis zur Anzeige eines einmaligen Kopplungscodes. Der Code ist im Prototyp ein Platzhalter und als Demo markiert.
6. Benutzerliste mit Workspace-Zuweisung und Rollen. Aktionen ändern den Prototyp-Zustand im Browser.
7. Workspace-Dashboard eines ausgewählten Servers, mit Rückweg ins Control Center.
8. Eine Fachansicht, Fahrzeuge oder Felder, mit Suche, Filter und Detail.
9. Workspace-Einstellungen und Connector-Status.
10. Build, Lint, dann Prüfung im Browser auf Desktop und einer schmalen Viewport-Breite. Visuelle Fehler werden in dieser Phase behoben.

Abnahme: [UX.md](UX.md) Abschnitt Akzeptanz und Prüfprotokoll. Keine Fach-Backends in dieser Phase. Der Prototyp liegt in `frontend/` und ist am 12. Oktober 2026 gebaut, gelintet und in beiden Breiten geprüft.

## Phase 3 — Grundgerüst

1. Laravel-13-Projekt, Verzeichnisplan aus [ARCHITECTURE.md](ARCHITECTURE.md), `APP_NAME` konfigurierbar.
2. React-Build aus dem Prototyp in `public/build` übernehmen und an echte, noch leere API-Zustände hängen.
3. Migrationen gegen MySQL 8 und MariaDB 10.11.
4. Web-Installer bis zur Sperre, inklusive Datenbanktest und Webroot-Test.
5. Login, Sessions, Rollen, IDOR-Tests für zwei Workspaces.
6. Deutsch und Englisch für den sichtbaren Rahmen und für API-Fehler.
7. Release-ZIP ohne Composer-Pflicht auf dem Zielhost. Optionales Compose für Self-Hosting.
8. CI: Lint, Unit, Feature, beide Datenbanken.

Abnahme: eine leere Installation auf einer PHP-8.3-Umgebung mit beiden Datenbanktypen, Login, ein Realm-Admin, kein offener Installer.

## Phase 4 — Erster Datenfluss

1. Backend holt den Web-API-Feed eines Workspace: Stats, Kartenbild, `careerSavegame`, `vehicles`, `economy`.
2. Control Center zeigt Erreichbarkeit, Spielerzahl, Map und Spielversion. `server.money` aus dem JSON wird nicht als Kontostand angezeigt.
3. Workspace zeigt Fahrzeugpositionen, vorhandene Füllstände, Farmland- und Feld-Anker sowie Periodenpreise. Feldformen, Tiere, Lager und Hofkonten bleiben „nicht verfügbar“.
4. Ein Dateitest listet `modSettings` und den Savegame-Ordner am gespeicherten Pfad. Schlägt er fehl, bleibt der Mod-Kanal auf „Datenpfad nicht verfügbar“, der Feed kann trotzdem online sein.
5. Danach der lesende Hello-Mod für die Lücken des Feeds. Kein Geld.
6. Ausfall des Feeds zeigt offline, ohne erfundene Zahlen. Demo-Daten bleiben in einem eigenen `data_mode`.

Abnahme: ein Workspace zeigt Werte aus dem Feed, die mit der Server-Webseite übereinstimmen, und einen zweiten Workspace, der leer und nicht verbunden bleibt.

## Phase 5 — Fachmodule

Reihenfolge nach Abhängigkeit:

1. Höfe innerhalb des Workspace.
2. Fahrzeuge, sobald der Server-Spike sie liefert.
3. Felder als Liste, danach Geometrie und Karte, sobald Punkte und die Koordinatentransformation belegt sind.
4. Tiere, Lager, Produktionen, Verkaufspreise.
5. Finanzübersicht mit klarer Trennung von Spielbeobachtung und Plattformrechnung.
6. Rechnungen als Dokumente sofort, Spielzahlung erst nach dem Geldtest auf einem Backup-Savegame.

Jedes Modul: Policy-Tests, leere und veraltete Daten, Deutsch und Englisch, Aktualisierung der betroffenen Dokumentation.

## Phase 6 — Tablet und Erweiterungen

1. GUI-XML-Prototyp nur für Funktionen, die Phase 4 und 5 serverseitig schon lesen.
2. Dokumentierte Erweiterungspunkte aus [PLUGIN-ARCHITECTURE.md](PLUGIN-ARCHITECTURE.md), noch ohne Marktplatz und ohne Fremdcode im Hauptprozess.

## Phase 7 — Release

Shared-Hosting-Durchlauf, beide Datenbanken, Update, Backup, Restore, Installer-Sperre, bekannte Einschränkungen im Changelog. Kein Release mit veralteter Dokumentation.

## Bewusst später

- zentraler Einrichtungsdienst für Menschen ohne eigenes Dashboard
- automatischer Upload über eine Nitrado-API, erst nach FS25-Test
- WebSockets
- Plugin-Ausführung durch Dritte
- Ingame-Zahlungen, bevor der Dedicated-Server-Test sie bestätigt
