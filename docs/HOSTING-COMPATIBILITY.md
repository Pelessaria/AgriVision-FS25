# Hosting-Kompatibilität der Spielserver

**Status:** Matrix. Ein Dedicated Server wurde am 12. Oktober 2026 nur lesend über die Web-API geprüft. Ein Mod und ein FTP-Zugriff wurden nicht getestet.  
**Stand:** 12. Oktober 2026

Profile beschreiben nur den Dateiweg und die Mod-Aktivierung. Sie enthalten keine Spiel- oder Connector-Logik. Eine Zelle bleibt „ungeklärt“, bis ein dokumentierter Versuch sie setzt.

SFTP und FTPS sind verschiedene Protokolle. Unverschlüsseltes FTP ist ein gesonderter, als unsicher markierter Weg. Zugangsdaten gehören in den verschlüsselten Host-Zugang des Dashboards, nie in das Mod-ZIP.

## 1. Legende

| Wert | Bedeutung |
| --- | --- |
| belegt | Herstellerdoku oder von uns wiederholter Versuch |
| community | öffentliche Anleitung oder Mod-Dokumentation, von uns nicht wiederholt |
| ungeklärt | keine ausreichende Quelle, oder Quelle ohne FS25-Bezug |
| nicht vorgesehen | widerspricht dem Betriebsmodell |

## 2. Matrix

`modSettings` ist der vorgesehene Normalfall: der Hoster gibt den FS-Ordner unter My Games frei, mindestens Mods, Savegames und `modSettings`. Das ist eine Betreiberangabe vom 12. Oktober 2026, kein von uns ausgeführter FTP-Login. Die Einrichtung speichert den konkreten Pfad nach einem erfolgreichen List-Versuch. Ein Hoster, der den Ordner verbirgt, bleibt die Ausnahme und wird im Workspace als „Datenpfad nicht verfügbar“ gezeigt.

Die Giants-Web-API ist unabhängig vom FTP. Auf dem geprüften Testserver antworteten Stats, Kartenbild und die Savegame-Dateien `careerSavegame`, `vehicles` und `economy`. Ob GPORTAL und Nitrado denselben Port aus dem Internet erreichbar machen, ist je Angebot noch offen. Eine Drittquelle sagt, die Web-API sei auf gemieteten Servern standardmäßig an ([Farm Sim Game](https://www.farmsimgame.com/Docs/Farming-Simulator-25/Dedicated-Servers/)).

| Umgebung | Mod hochladen | Protokoll | Neustart durch uns | `modSettings` von außen lesbar | Web-API-Feed | Stand |
| --- | --- | --- | --- | --- | --- | --- |
| Eigener PC, Einzelspieler | ZIP nach `Documents/My Games/FarmingSimulator2025/mods` | lokales Dateisystem | Spieler startet den Spielstand neu | ja, derselbe Rechner | nur wenn ein Dedicated-Server-Webport läuft, im Einzelspieler nicht angenommen | community für den Mods-Ordner ([Farmhand](https://github.com/getflowkits/fs25-farmhand-mod)) |
| Dedicated Server mit veröffentlichter Web-API | ZIP in den Mods-Ordner | Feed per HTTP oder HTTPS, Dateien separat | nicht über den Feed | erwartet, Pfad prüfen | **belegt** auf einem Testserver, Spiel 1.24, siehe [FS25-INTEGRATION.md](FS25-INTEGRATION.md) Abschnitt 0 | Web-Login für `Pelle-Test` schlug mit zwei Passwörtern fehl. Steuerbefehle wurden nicht gesendet |
| Eigener Dedicated Server mit Shell | ZIP in das Mods-Verzeichnis | SFTP oder lokales Dateisystem, plus Feed wenn der Port offen ist | Betreiber startet den Prozess neu | erwartet, Pfad prüfen | ungeklärt, bis die URL dieses Servers geprüft ist | |
| GPORTAL | Panel-Upload und FTP werden in Community-Material beschrieben | oft FTP Port 21, community; SFTP ungeklärt | Panel des Betreibers | erwartet, Pfad ungeprüft | ungeklärt für diesen Anbieter | Automatischer Upload ist nicht freigegeben |
| Nitrado | Panel und FTP werden community-seitig angenommen | ungeklärt für FS25 | ungeklärt | erwartet, Pfad ungeprüft | ungeklärt für FS25 | Die allgemeine Nitrado-Dateiserver-API bleibt für FS25 deaktiviert ([NitrAPI](https://github.com/nitrado/NitrAPI)) |
| Anderer Mietserver mit SFTP, FTPS oder FTP | manuell | das gewählte Protokoll, FTP nur mit Warnung | manuell | erwartet, Pfad prüfen | URL und Code je Server, bis zum ersten erfolgreichen GET ungeklärt | |

## 3. Was ein Profil später enthalten darf

Erst nach einem Testlauf:

- unterstützte Protokolle und die Authentifizierung, die wirklich funktioniert hat
- Mods-Verzeichnis und der Pfad zu `modSettings/<Modname>/`
- Klicks für die Mod-Aktivierung
- ob ein Neustart nötig ist und wer ihn auslöst
- ob der Spielprozess ausgehend HTTPS verbinden darf, falls ein Lua-Client nachgewiesen wurde
- welche Connector-Befehle auf diesem Anbieter liefen
- welche Schritte manuell bleiben

Bis dahin zeigt die Oberfläche bei GPORTAL, Nitrado und „anderer Hoster“ den Text „Kompatibilität nicht geprüft“ und den manuellen Pfad aus [INSTALLATION.md](INSTALLATION.md).

## 4. Geführter manueller Weg, für jeden ungeklärten Hoster

1. Connector-ZIP aus dem Workspace herunterladen. Das ZIP enthält keinen Zugang.
2. Den vom Hoster genannten Mods-Ordner öffnen.
3. Das ZIP hochladen, nicht entpacken, wenn der Server Multiplayer-Mods als Archiv erwartet.
4. Den Mod in der Serveroberfläche aktivieren.
5. Den Server neu starten, so wie der Hoster das beschreibt.
6. Im Dashboard „Verbindung prüfen“ ausführen.
7. Wenn keine `hello.xml` erscheint: der Assistent nennt den erwarteten Dateinamen und bittet, den Profilordner im Dateizugang zu suchen. Gefundenen Pfad kann der Workspace-Admin speichern. Wird nichts gefunden, bleibt der Status „Mod möglicherweise aktiv, Datenpfad nicht verfügbar“.

## 5. Konsequenz für die Produktzusage

Live-Daten aus der Web-API sind auf einem Dedicated Server mit erreichbarem Feed belegt. Alles darüber hinaus, insbesondere Hofkonten, Feldformen und Spielaktionen, bleibt an den geprüften Dateipfad oder an den Mod gebunden. Die Oberfläche behauptet für einen Hoster nur die Fähigkeiten, die auf diesem Hoster schon geantwortet haben.
