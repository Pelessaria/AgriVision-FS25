# Installation

**Status:** Bedienschritte für einen Installer, den es noch nicht gibt. Nichts davon ist auf einem Webspace durchgespielt.  
**Stand:** 12. Oktober 2026

Technische Begründung: [INSTALLATION-STRATEGY.md](INSTALLATION-STRATEGY.md). Webspace-Voraussetzungen: [SHARED-HOSTING.md](SHARED-HOSTING.md). Spielserver: [HOSTING-COMPATIBILITY.md](HOSTING-COMPATIBILITY.md).

## 1. Was du vorher bereithältst

- einen Webspace mit PHP 8.3 oder neuer und HTTPS
- eine leere Datenbank, MariaDB 10.11+ oder MySQL 8.0+
- Hostname, Datenbankname, Benutzer und Passwort aus dem Kundenlogin
- den Anzeigenamen der Installation, falls er vom Vorschlag „FS25 Management Platform“ abweichen soll

Du brauchst keine Shell und kein Docker.

## 2. Dateien hochladen

1. Lade das Release-ZIP herunter, sobald es veröffentlicht ist. In Phase 1 gibt es dieses ZIP noch nicht.
2. Entpacke es auf deinem Rechner.
3. Öffne im Hosting-Panel den Dateimanager oder verbinde dich per SFTP.
4. Lege die Anwendungsdateien so ab, dass das Webroot auf den Ordner `public` zeigt. Wenn dein Hoster das Webroot nicht umstellen kann, folge dem Hinweis im Installer zur geschützten Ein-Verzeichnis-Variante. Der Installer prüft, ob sensible Dateien von außen unsichtbar sind.
5. Lege die Datenbank im Panel an, falls noch keine existiert. Notiere den Server-Hostnamen. Er lautet oft nicht `localhost`.

## 3. Installer im Browser

Öffne `https://deine-domain.example/install`.

1. Der Installer zeigt PHP-Version und fehlende Erweiterungen. Behebe fehlende Punkte im Panel und lade die Seite neu.
2. Bestätige die HTTPS-Adresse.
3. Wähle MariaDB oder MySQL, trage die Zugangsdaten ein und starte den Verbindungstest.
4. Starte das Anlegen der Tabellen.
5. Vergib Name, E-Mail und Passwort des Administrators. Es gibt kein vorausgefülltes Passwort.
6. Trage den Anzeigenamen und die öffentliche URL ein.
7. Lies die Abschlussseite. Der Installer ist danach nicht mehr erreichbar.

Wenn eine Meldung ohne technische Details erscheint, steht die ausführliche Ursache in `storage/logs` auf dem Server, ohne Datenbankpasswort.

## 4. Danach im Control Center

1. Melde dich an.
2. Lege einen Workspace für genau einen Spielserver an.
3. Lade das Connector-ZIP herunter.
4. Lade es im Panel des Spielhosters in den Mods-Ordner und aktiviere es. Entpacke es nicht, wenn der Server Archive erwartet.
5. Starte den Spielserver über das Panel des Hosters neu.
6. Wähle im Dashboard „Verbindung prüfen“.

Kommt keine Verbindung zustande, bleibt der Workspace auf „nicht verbunden“. Die Karte zeigt keinen erfundenen Kontostand. Den weiteren Weg beschreibt der Assistent abhängig vom gewählten Hoster-Profil. Für GPORTAL, Nitrado und unbekannte Hoster ist das Profil derzeit ungeprüft.

## 5. Updates

1. Exportiere die Datenbank über das Panel oder `mysqldump` / `mariadb-dump`.
2. Sichere den Ordner `storage` und die Datei `.env`.
3. Ersetze die Anwendungsdateien durch das neue ZIP, ohne `.env` und ohne `storage/installed.lock` zu überschreiben.
4. Melde dich als Administrator an und starte den angezeigten Migrationsschritt.

## 6. Häufige Irrtümer

| Beobachtung | Bedeutung |
| --- | --- |
| Installer verlangt PHP 8.3 | Im Panel die PHP-Version anheben. 8.2 ist zu alt |
| Datenbanktest schlägt fehl | Hostnamen aus den Panel-Details verwenden, Passwort neu setzen, Rechte prüfen |
| Seite zeigt den Ordner `vendor` im Browser | Webroot steht falsch oder die Schutzregeln fehlen. Installation nicht als fertig betrachten |
| Workspace bleibt offline | Mod nicht aktiv, Server nicht neu gestartet, oder `modSettings` ist über den Dateizugang nicht sichtbar |
| Zahlen fehlen | der Connector hat sie nicht gemeldet. Die Oberfläche zeigt „nicht verfügbar“ |
