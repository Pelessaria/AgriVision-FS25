# Backup und Wiederherstellung

**Status:** Verfahren für die spätere Anwendung. Es gibt noch keine Datenbank, die zu sichern wäre.  
**Stand:** 12. Oktober 2026

## 1. Was gesichert wird

| Bestandteil | Enthält Geheimnisse | Wie |
| --- | --- | --- |
| Datenbankexport | Passwort-Hashes, verschlüsselte Host-Zugänge, Spielsnapshots | Panel-Export, `mysqldump` oder `mariadb-dump` |
| `.env` | `APP_KEY`, Datenbankpasswort | Dateikopie außerhalb des Webroots |
| `storage/app` | hochgeladene Workspace-Icons, Installer-Sperre | Dateikopie |
| Connector-ZIP | keine Zugangsdaten | kann neu gebaut werden |
| Spielstand auf dem Gameserver | der eigentliche FS25-Save | Sache des Spielhosters, vor jedem Geldtest zusätzlich manuell |

Ein Datenbankexport ohne `.env` lässt sich nicht sinnvoll entschlüsseln, weil die Host-Zugänge an `APP_KEY` hängen. Beide Teile werden zusammen und mit denselben Zugriffsbeschränkungen gelagert.

## 2. Wann

- vor jedem Anwendungs-Update
- vor dem ersten echten Geldbefehl auf einem Test-Savegame
- regelmäßig nach eigenem Rhythmus des Betreibers, weil Version 1 keinen automatischen Dump erzwingt, solange kein Cron existiert

Wer Cron oder einen VPS hat, kann den Dump planen. Shared Hosting ohne Cron nutzt den Export im Panel. Die Anwendung erinnert den Realm-Admin im Control Center, wenn seit dem letzten manuell quittierten Backup ein Update ansteht. Das ist eine Erinnerung, kein Nachweis, dass die Datei existiert.

## 3. Wiederherstellen

1. Anwendung derselben Version bereitlegen, inklusive des `.env`, das zum Export gehört.
2. Leere Datenbank anlegen.
3. Export einspielen.
4. `storage/` zurückkopieren.
5. Im Browser anmelden und einen Workspace öffnen.
6. „Verbindung prüfen“ ausführen. Der Connector auf dem Spielserver bleibt davon unberührt.

Ein Export von MySQL nach MariaDB oder umgekehrt wird in Phase 3 geprüft. Bis das grün ist, wird in dieselbe Datenbankfamilie zurückgespielt. Siehe [DATABASES.md](DATABASES.md).

## 4. Aufbewahrung

Exporte liegen nicht im Webroot. Der Installer-Test, der sensible Dateien von außen holt, gilt auch für einen Ordner `backups/`, falls jemand ihn unter `public/` anlegt. Der geplante Ort ist `storage/backups`, vom Webserver nicht ausgeliefert, zusätzlich eine Kopie außerhalb des Servers.

Alte Exporte werden vom Betreiber gelöscht. Die Anwendung löscht sie in Version 1 nicht selbst, weil ein stilles Löschen der einzigen Kopie schlechter ist als ein voller Ordner.

## 5. Spielserver

Die Plattform sichert den FS25-Savegame nicht zuverlässig, solange der Dateizugang `modSettings` noch nicht einmal für den Connector nachgewiesen ist. Vor Tests mit `changeBalance` erstellt der Betreiber im Panel des Spielhosters eine Kopie des Savegames. Ein fehlgeschlagenes Restore des Dashboards setzt kein Geld im Spiel zurück; das kann nur die Savegame-Kopie.
