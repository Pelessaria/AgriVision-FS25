# Datenbanken

**Status:** MySQL/MariaDB-Ziele festgelegt. Workspace-Migration und `docker-compose` Profile sind vorhanden; Deploy-Test: [DEPLOY-TEST.md](DEPLOY-TEST.md).  
**Stand:** 14. Oktober 2026

## 1. Unterstützte Versionen

Laravel 13 nennt als Framework-Untergrenze MariaDB 10.3+ und MySQL 5.7+ ([Laravel Database](https://laravel.com/docs/13.x/database)). Diese Untergrenzen sind für ein neues Projekt zu alt: MySQL 5.7 und frühe MariaDB-10.x-Zweige sind außerhalb üblicher Security-Unterstützung.

Die Anwendung unterstützt:

| System | Versionen | Treiber in Laravel |
| --- | --- | --- |
| MySQL | 8.0 und 8.4 | `mysql` |
| MariaDB | 10.11 und 11.4 | `mariadb` |

10.11 ist der Zweig, den STRATO für neue Datenbanken ausweist. 11.4 ist der nächste LTS-Zweig und wird in CI mitgezogen, sobald die Tests stehen. 8.4 ist der MySQL-LTS-Zweig neben dem noch verbreiteten 8.0.

Der Installer liest `SELECT VERSION()` und akzeptiert nur diese Familien und Untergrenzen. Ein MySQL-Server, der sich als MariaDB meldet, wird mit dem MariaDB-Treiber verbunden. Der Treiber wird nicht geraten: der Betreiber wählt den Typ, der Test prüft die Versionszeichenkette.

Ältere Server erhalten eine klare Ablehnung mit dem geforderten Mindeststand.

## 2. Gemeinsame SQL-Teilmenge

Erlaubt im Anwendungscode:

- InnoDB
- `utf8mb4` / `utf8mb4_unicode_ci` auf beiden Systemen. `utf8mb4_0900_ai_ci` ist MySQL-8-spezifisch und wird nicht verlangt
- Primärschlüssel, Fremdschlüssel, eindeutige Indizes, normale Indizes
- Transaktionen für Rechnungsstatus und Mitgliedschaften
- JSON-Spalten als Dokumente

Nicht im Kern:

- `JSON_TABLE`, MySQL-only JSON-Pfadabfragen als einzige Filtermöglichkeit
- MariaDB-Vector
- gespeicherte Prozeduren als Pflicht
- unsichtbare Spalten, bestimmte Instant-DDL-Besonderheiten als angenommene Gleichheit
- unterschiedliche Standard-SQL-Modes als stilles Verhalten. Der Installer setzt den Verbindungs-SQL-Mode explizit und protokolliert ihn

Abweichungen, die in Tests auffallen, werden in diesem Dokument und im Changelog benannt. Die Anwendung gilt erst dann als beide-Systeme-fähig, wenn die CI beide Dienste grün hat.

## 3. Verbindung

| Wert | Hinweis |
| --- | --- |
| Host | vom Hoster, oft nicht `localhost` |
| Port | 3306, sofern der Hoster nichts anderes nennt |
| Datenbankname, Benutzer, Passwort | nur für diese Datenbank |
| Zeichensatz | `utf8mb4` |
| Kollation | `utf8mb4_unicode_ci` |
| TLS zur Datenbank | einschalten, wenn der Hoster es anbietet. Zertifikatsprüfung nicht global abschalten |

Rechte: `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `ALTER`, `INDEX`, `DROP` auf dieser Datenbank.

Passwörter stehen in `.env` außerhalb des Webroots oder in der geschützten Fallback-Struktur. Fehlermeldungen enthalten sie nicht.

## 4. Migrationen

Laravel-Migrationen sind die einzige Schemaquelle. Sie laufen:

- im Web-Installer ohne Shell
- in CI gegen beide Dienste
- bei Updates über einen bestätigten Admin-Aufruf

Ein fehlgeschlagener erster Lauf lässt die Sperrdatei ungeschrieben, damit der Installer nach Korrektur der Zugangsdaten erneut laufen kann. Sobald die Sperre existiert, sind Schemaänderungen nur noch Migrationen, kein zweiter Installer.

## 5. Backup und Wiederherstellung

Kurzform; Ablauf in [BACKUP-RESTORE.md](BACKUP-RESTORE.md).

- logischer Export mit `mysqldump` beziehungsweise `mariadb-dump`, kompatibel genug, um in die jeweils andere Familie derselben Anwendung zurückzukehren, wird in Phase 3 geprüft und hier korrigiert, falls die Dateien nicht austauschbar sind
- vor jedem Update ein Export
- Wiederherstellen in eine leere Datenbank, danach Anwendungsdateien derselben Version
- Export enthält verschlüsselte Host-Zugänge und Passwort-Hashes und wird wie ein Geheimnis gelagert

Shared Hosts ohne Shell bieten oft phpMyAdmin oder einen Panel-Export. Der Installationsleitfaden verweist auf diesen Panel-Weg als gleichwertig zum CLI-Dump.

## 6. Tests, die noch fehlen

- Migration vor und zurück auf MySQL 8.0, MySQL 8.4, MariaDB 10.11, MariaDB 11.4
- Zeichensatz eines Hofnamens mit Umlauten und eines Spieltexts außerhalb von Latin-1
- zwei parallele Installer-Läufe, einer gewinnt
- Verbindungstest mit absichtlich falschem Passwort zeigt keine Zugangsdaten
