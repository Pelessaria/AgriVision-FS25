# Plugin-Architektur

**Status:** Grenzen für spätere Erweiterungen. In Version 1 gibt es keinen Plugin-Marktplatz und keinen Fremdcode-Lader.  
**Stand:** 12. Oktober 2026

## 1. Ziel

Community-Erweiterungen sollen später möglich sein, ohne dass Version 1 ein allgemeines Plugin-System mit vollem Prozesszugriff einführt. Ein Manifest ist keine Sicherheitsgrenze. Ein gewöhnliches JavaScript- oder Lua-Modul ist keine Sandbox.

## 2. Arten, die nicht vermischt werden

| Art | Läuft in | Rechte | Version 1 |
| --- | --- | --- | --- |
| Frontend-Erweiterung | Browser des Benutzers | nur das, was die API diesem Benutzer schon erlaubt | nicht geladen |
| Backend-Erweiterung | PHP-Prozess | Datenbank und Host-Zugänge dieser Installation, sobald sie im Prozess liegt | nur erste-Parte-Module unter `app/Modules` |
| Datenintegration | PHP-Prozess, ausgehendes HTTPS | die Geheimnisse, die der Betreiber dafür speichert | nicht vorhanden |
| Connector-Modul | Lua auf dem Spielserver | das, was der Dedicated Server dem Mod erlaubt, inklusive Spielgeld, falls die Funktion existiert | ein eigener Mod, keine Dritt-Skripte |
| Tablet-Modul | GIANTS-GUI im Spiel | Client-Oberfläche, keine Web-APIs des Browsers | Phase 6 |

Fremdes Backend- oder Connector-Paket mit denselben Rechten wie der Kern ist ein voller Zugriff auf die Installation beziehungsweise auf den Spielstand. Das wird nicht als „isoliertes Plugin“ bezeichnet.

## 3. Was Version 1 stattdessen tut

- Module mit eigenen Routen, Policies und Übersetzungen.
- HTTP-API `/api/v1`, die stabil genug bleibt, dass ein späteres externes Programm dagegen arbeiten kann, ohne im PHP-Prozess zu wohnen.
- Feste Connector-Befehlsliste statt freiem Skript.
- Metadaten an den Modulen: Kennung, Version, Abhängigkeit von einer API-Version. Das ist Dokumentation, kein Lader.

Ein externes Programm, das nur die HTTP-API mit einem normalen Benutzerkonto nutzt, ist der sicherste frühe Erweiterungspunkt. Es sieht nur die Workspaces dieses Kontos.

## 4. Späteres Modell, falls Community-Code im Produkt laufen soll

Vor einem solchen Feature werden diese Punkte entschieden und nicht stillschweigend angenommen:

- Signierte Pakete und eine Kennung je Plugin.
- Kompatibilitätsbereich der API-Version.
- Aktivieren und Deaktivieren ohne Dateien aus dem Webroot auszuführen, die ein Besucher hochgeladen hat.
- Prozess- oder mindestens Berechtigungsisolation für Backend-Code. Ohne Isolation bleibt „Plugin“ ein vom Betreiber bewusst deploytes Modul mit vollen Rechten, und die Oberfläche sagt das.
- Eigene Berechtigungen je Erweiterung, die die Workspace-Rolle nicht übersteigen.
- Kein Plugin erhält pauschal die Host-Zugangsdaten aller Workspaces.
- Connector-Erweiterungen sind weitere Befehlsmodule, die der Betreiber in den Mod packt und neu startet. Sie laufen nicht dynamisch aus dem Dashboard nach.

## 5. Versionierung

Die HTTP-API trägt `/api/v1`. Brechende Änderungen werden `/api/v2`. Zusatzfelder in JSON sind erlaubt und werden von Clients ignoriert, die sie nicht kennen.

Connector-Dateien tragen `schema_version`. Ein Backend, das die Version nicht kennt, lehnt den Batch ab und zeigt eine verständliche Aktualisierungsmeldung.

## 6. Nicht-Ziele

- ein Marktplatz in Version 1
- die Behauptung, PHP-`include` oder Lua-`dofile` erzeuge eine Sandbox
- das Nachladen von Lua aus dem Dashboard auf den Spielserver ohne neuen Mod-Start und ohne Wissen des Betreibers
