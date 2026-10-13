# Self-Hosting

**Status:** Optionale Betriebsart. Compose-Dateien sind noch nicht angelegt.  
**Stand:** 12. Oktober 2026

## 1. Wann dieser Weg passt

Ein eigener VPS oder Rechner ist sinnvoll, wenn der Betreiber Docker oder einen Webserver verwalten will, Backups selbst steuert oder den Dateiabruf zum Spielserver aus einem Netz heraus betreibt, das der Shared Hoster nicht erreicht.

Self-Hosting ist keine Voraussetzung. Wer nur Webspace hat, bleibt bei [SHARED-HOSTING.md](SHARED-HOSTING.md).

## 2. Geplantes Compose

Zwei Profile, damit beide Datenbanken echt getestet werden können:

| Dienst | Rolle |
| --- | --- |
| `app` | PHP 8.3 mit den Laravel-Erweiterungen, Document Root `public/` |
| `web` | Nginx oder Caddy mit HTTPS |
| `db` | im Profil `mariadb` MariaDB 10.11, im Profil `mysql` MySQL 8.0 |

Nicht im Standard-Compose: Redis, Queue-Worker, WebSocket-Server, Mailcatcher im Produktivprofil.

Dieselben Migrationen wie auf dem Webspace. Der Erststart nutzt denselben Installer über HTTPS oder, für eine reine Entwicklungsmaschine, die ausdrückliche HTTP-Bestätigung.

Geheimnisse kommen aus einer `.env`, die nicht ins Image kopiert wird.

## 3. Betrieb mit Shell, ohne Docker

Wer kein Compose will, installiert PHP 8.3, einen Webserver mit Root `public/` und MariaDB oder MySQL. Der Web-Installer bleibt gültig. `php artisan` ist dann zusätzlich benutzbar für Migrationen und den Scheduler.

Ein Cron-Eintrag für `schedule:run` ist hier möglich und beschleunigt Aufräumen und Abholen. Die Anwendung bleibt korrekt, wenn der Eintrag fehlt.

## 4. Lokale Bridge zum Spiel

Auf dem Spielrechner, nicht im Web-Container:

- liest `modSettings/<Modname>/`
- sendet ausgehendes HTTPS an die öffentliche URL des Dashboards
- authentifiziert sich mit dem Connector-Geheimnis, das beim Koppeln einmal angezeigt wurde
- schreibt Befehlsdateien für den Mod

Die Bridge ist ein optionales kleines Programm. Sie ist der belegte Transport für „Lua schreibt Dateien, ein Prozess daneben spricht HTTP“. Auf einem gemieteten Server ohne Shell steht sie nicht zur Verfügung.

## 5. Netz

- Das Dashboard ist über HTTPS erreichbar.
- Der Spielserver braucht für die Bridge ausgehendes HTTPS zum Dashboard.
- Eingehende Ports am Spielserver bleiben zu.
- Der Dedicated-Server-Webadmin (häufig TCP 8080 und 8443) wird nicht veröffentlicht.

## 6. Vergleich

| | Shared Hosting | Self-Hosting |
| --- | --- | --- |
| Root | nicht nötig | je nach VPS vorhanden |
| Dauerprozess | nicht nötig | möglich, in Version 1 nicht nötig |
| Cron | oft fehlend | üblich |
| Datenbank | MariaDB oder MySQL des Hosters | derselbe Anwendungscode, selbst betrieben |
| Updates | ZIP und bestätigter Migrationsaufruf | ZIP oder Image, dieselbe Migration |
| Datenschutz | Webspace-Anbieter sieht die Daten | der Betreiber sieht die Daten; ein zukünftiger Zentraldienst ist daran nicht beteiligt |
| Live-Takt | durch PHP-Laufzeit und Dateiabruf begrenzt | Bridge kann häufiger senden, das Spiel bleibt intervallbasiert |

Ein zentral gehostetes Dashboard, betrieben von den Autoren dieser Software, ist nicht Teil von Version 1. Falls es später entsteht, bleibt eine vollständig eigene Installation ohne dieses Zentrum möglich. Konten, Connector-Geheimnisse und Spielsnapshots lägen dann beim Betreiber des Zentrums; das wäre ein eigener Datenschutztext und eine ausdrückliche Wahl.

## 7. Offene Punkte

- Konkrete Image-Versionen werden beim ersten Compose gepinnt, nicht in diesem Plan auf eine Tagesversion festgeschrieben.
- Ressourcenbedarf des Containers wird gemessen, sobald die Anwendung existiert.
