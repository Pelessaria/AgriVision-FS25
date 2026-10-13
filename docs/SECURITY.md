# Sicherheit

**Status:** Bedrohungsmodell für die geplante Architektur. Noch keine Implementierung und kein Penetrationstest.  
**Stand:** 12. Oktober 2026

## 1. Schützenswerte Güter

- Benutzerkonten und Sitzungen
- Zuordnung, welcher Benutzer welchen Workspace sieht
- Connector-Geheimnisse und Kopplungscodes
- Dateizugangsdaten der Spielhoster
- Spielstände, insbesondere Geld
- Audit, das nachvollziehbar bleibt, ohne Geheimnisse zu enthalten
- Installationsschlüssel `APP_KEY`

Ein kompromittiertes Dashboard-Konto eines Mitarbeiters darf nicht den ganzen Spielserver steuern. Geldaufträge sind eine eigene Berechtigung, an einen Workspace und zwei Höfe gebunden, und bleiben bis zur Connector-Bestätigung unbestätigt.

## 2. Annahmen über Angreifer

- anonyme Nutzer im Internet gegen das Web-Frontend
- ein eingeloggter Benutzer, der fremde Workspace-UUIDs ausprobiert
- ein Workspace-Admin, der Mitglieder eines anderen Workspace sucht
- jemand mit einem alten Kopplungscode oder einem gestohlenen Connector-Geheimnis
- jemand mit Zugang zum Webspace-Dateisystem, aber ohne Datenbankpasswort, und umgekehrt
- ein bösartiges späteres Plugin mit denselben Rechten wie der PHP-Prozess

Nicht das Ziel von Version 1: ein Angreifer mit Root auf dem Webserver. Wer den PHP-Benutzer oder `APP_KEY` hat, kann die verschlüsselten Host-Zugänge lesen. Das wird dokumentiert, nicht wegbehauptet.

## 3. Gegenmaßnahmen

### 3.1 Konten

- Passwörter nur als `password_hash` mit dem PHP-Standardalgorithmus.
- Kein Standardpasswort im Installer. Der erste Admin vergibt das Passwort im Einrichtungsdialog. Mindestlänge 12.
- Sitzung als Cookie: `HttpOnly`, `Secure` sobald HTTPS erkannt ist, `SameSite=Lax`.
- Session-ID wird bei Login neu vergeben.
- Deaktivierte Benutzer behalten keine gültige Sitzung.
- Rate-Limit auf Login, Passwort-Reset und Kopplung. Speicherung in der Datenbank, damit kein Redis nötig ist.
- Passwort-Reset-Token nur als Hash, kurze Laufzeit, einmalig.

### 3.2 Autorisierung

- Jede geschützte Route hat eine Policy.
- Workspace-Kontext kommt aus der Mitgliedschaft, nach Prüfung der gesendeten ID.
- Fremde IDs antworten einheitlich mit 404, damit keine Existenz von Workspaces anderer Installationen bestätigt wird. Innerhalb der eigenen Installation darf ein Realm-Admin 403 sehen, wenn eine Aktion fehlt.
- IDOR-Tests sind Abnahmekriterium, nicht eine optionale Prüfung.
- Rechnungszahlung prüft Rolle, Status, Betrag, beide Hof-IDs im selben Workspace und einen aktiven Connector.

### 3.3 Web-Risiken

- SQL nur über den Query Builder oder gebundene Parameter.
- React-Textausgabe als Text. HTML aus Spielnamen wird nicht mit `dangerouslySetInnerHTML` gerendert.
- CSRF für Cookie-Routen über das Laravel-Token.
- CORS bleibt gleiche Origin. Eine zusätzliche Origin wird nur explizit konfiguriert und nie als `*` zusammen mit Cookies.
- Sicherheitsheader: `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY` oder eine enge CSP. Die genaue CSP wird mit dem Vite-Build abgestimmt, damit sie nicht nur auf dem Papier steht.
- `APP_DEBUG=false` im Produktivbetrieb. Fehlermeldungen der Datenbank nennen weder Passwort noch Host-Benutzer.
- Request-Größenlimit für Connector-Snapshots. Übergröße wird abgelehnt.
- Validierung jedes Feldes, unbekannte Befehlsarten werden abgelehnt.

### 3.4 Connector und Aktionen im Spiel

- Kopplungscode ist kurzlebig, zufällig, wird gehasht gespeichert und nach Erfolg gelöscht.
- Der Feed-Code des Dedicated Servers ist ein Leserecht. Er wird verschlüsselt gespeichert, nur serverseitig verwendet und nie an den Browser oder in ein Mod-ZIP gegeben. Der Feed setzt auf dem geprüften Server `Access-Control-Allow-Origin: *`; deshalb wäre ein Code im Frontend von jeder Website lesbar.
- Klartext-HTTP zum Feed ist erlaubt, wenn der Workspace-Admin es ausdrücklich bestätigt. Sonst wird HTTPS verlangt.
- Ein Web-Benutzername der Giants-Oberfläche ist kein Feed-Code. Zwei Anmeldungen als `Pelle-Test` kamen nicht zustande. Start, Stop und Neustart sind nicht Teil des Lesewegs. Falls ein solcher Benutzer später funktioniert, bleibt jede Zustandsänderung an `workspace_admin` und eine Bestätigung gebunden.
- Connector-Geheimnis wird einmal angezeigt und nur als Hash gespeichert, sobald der Transport ein Geheimnis braucht.
- Rotation erzeugt ein neues Geheimnis und entwertet das alte nach einem kurzen Überlappungsfenster, das im Audit steht.
- Befehle tragen eine UUID. Der Mod führt eine UUID höchstens einmal aus.
- Wiederholte HTTP- oder Dateilieferungen derselben Batch-UUID sind Idempotent.
- Timeout einer Zahlung setzt `unknown` und startet einen Abgleich. Er bucht die Rechnung nicht als bezahlt und nicht als endgültig gescheitert.
- Der Connector führt in der ersten Version keine freien Lua- oder Shell-Strings aus. Die Befehlsliste ist eine feste Enumeration.

### 3.5 Host-Zugangsdaten

- SFTP und FTPS sind die vorgesehenen Protokolle.
- Unverschlüsseltes FTP ist eine eigene Wahl mit Pflichtbestätigung und einem sichtbaren Hinweis in der Workspace-Karte.
- Zugangsdaten stehen nicht im Mod-ZIP, nicht in Exporten der Oberfläche und nicht im Audit.
- Verschlüsselung mit `APP_KEY`. Wer `APP_KEY` rotiert, muss die Ciphertexte neu verschlüsseln. Dieser Ablauf wird vor der ersten echten Nutzung implementiert und getestet.
- Ein Verbindungstest zeigt „erreichbar“ oder eine allgemeine Fehlermeldung. Er zeigt nicht das Passwort und nicht die volle Serverantwort, wenn diese Zugangsdaten enthalten könnte.

### 3.6 Installer

- Nach Erfolg schreibt er eine Sperrdatei und deaktiviert die Installationsrouten.
- Ein paralleler zweiter Lauf erhält die Sperre nicht, solange der erste läuft.
- Er setzt keine offenen Debug-Flags und legt keinen zweiten Admin ohne das gesetzte Passwort an.
- Er sendet keine Geheimnisse an einen zentralen Dienst. Ein solcher Dienst ist nicht Teil von Version 1.

### 3.7 Dateien und Backups

- `.env` und `storage/` liegen außerhalb des Webroots, wenn der Hoster das hergibt.
- Der Installer fordert eine URL einer Testdatei an, die gesperrt sein muss.
- Logs rotieren und enthalten keine Authorization-Header und keine Host-Geheimnisse.
- Backups der Datenbank enthalten die verschlüsselten Host-Zugänge. Sie werden wie Geheimnisse behandelt. Siehe [BACKUP-RESTORE.md](BACKUP-RESTORE.md).
- Release-Zips werden vor der Veröffentlichung auf `.env`, private Schlüssel und FTP-Passwörter geprüft.

### 3.8 Abhängigkeiten

- PHP- und JavaScript-Abhängigkeiten werden in CI auf bekannte Lücken geprüft, sobald das Projekt existiert.
- Updates laufen regelmäßig. Ein grüner Scan ist keine Garantie.

## 4. Bedrohungen und geplante Reaktion

| Bedrohung | Reaktion |
| --- | --- |
| Gestohlene Session-Cookie | `HttpOnly`, `Secure`, kurze Laufzeit, Abmeldung bei Passwortwechsel, HTTPS als Installationsvoraussetzung |
| IDOR über Workspace-UUID | Policy plus Tests mit zwei Benutzern und zwei Workspaces |
| SQL-Injection über Filter | gebundene Parameter, Sortierspalten aus einer Whitelist |
| XSS über Spielernamen oder Feldnamen | Textausgabe |
| CSRF | Token bei Cookie-Authentifizierung |
| Replay eines Zahlungsauftrags | eindeutige Auftrags-ID, Mod speichert ausgeführte IDs bis zur Bestätigung durch das Backend |
| Doppelklick auf „Bezahlen“ | Idempotency-Key |
| Liegengebliebener Befehl nach Reconnect | derselbe Schlüssel, Status `unknown` bis zum Abgleich |
| Offener Installer | Sperrdatei und deaktivierte Route |
| Geheimnis im Fehlerdialog | allgemeine Meldung, Detail nur im gesäuberten Log |
| FTP-Mitschnitt | FTP nur mit Warnung; SFTP/FTPS bevorzugt |
| Bösartiges Community-Plugin | in Version 1 kein Fremdcode im Prozess; siehe [PLUGIN-ARCHITECTURE.md](PLUGIN-ARCHITECTURE.md) |
| Kompromittierter Mitarbeiter-Account | keine globale Shell auf dem Spielserver; Zahlungen nur mit Rolle `manager` oder höher und mit Spielbestätigung |
| Datenleck zwischen Höfen | jede Abfrage mit `workspace_id` und, wo fachlich nötig, `game_farm_id` |

## 5. Audit

Diese Ereignisse werden geschrieben: Login-Erfolg und -Misserfolg ohne Passwort, Benutzer angelegt oder deaktiviert, Rolle geändert, Mitgliedschaft geändert, Workspace angelegt, deaktiviert oder gelöscht, Kopplung, Rotation, Host-Zugang geändert ohne das Geheimnis, Zahlung angestoßen, Connector-Ergebnis, Installer abgeschlossen.

Audit-Zeilen sind anhängbar. Die Anwendung bietet in Version 1 kein UI zum Löschen einzelner Sicherheitsereignisse.

## 6. Bekannte Grenzen

- Verschlüsselung der Host-Zugänge endet bei Kenntnis von `APP_KEY` und der Datenbank.
- Shared Hosting teilt sich den Server mit anderen Kunden. Die Isolation ist die des Hosters.
- Ein Lua-Mod läuft mit den Rechten des Spielservers. Der Mod selbst muss klein bleiben und keine allgemeinen Dateipfade beschreiben.
- Ohne eigenen Test ist dieses Dokument ein Plan, kein Nachweis.
