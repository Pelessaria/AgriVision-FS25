# UX und Designrichtung

**Status:** Phase-2-Prototyp steht in `frontend/`. Abnahme anhand des Prüfprotokolls.  
**Stand:** 12. Oktober 2026

## 1. Referenz und Entscheidung

[puikinsh/nalika](https://github.com/puikinsh/nalika) ist die strukturelle Referenz: dunkles Admin-Layout, linke Sidebar, obere Kopfzeile, zentraler Inhalt, kompakte Navigation, Karten, Tabellen, Formulare. Der Changelog 2.0.0 vom 13. Februar 2026 beschreibt ein auf Bootstrap 5.3 umgebautes Colorlib-Template.

Eine LICENSE-Datei war zum Analysezeitpunkt unter `https://raw.githubusercontent.com/puikinsh/nalika/master/LICENSE` nicht abrufbar. Code, CSS, Icons und Bilder aus dem Repository werden nicht kopiert. Das Designsystem entsteht eigenständig. Bootstrap kann als Layout- und Komponentenbasis dienen, das Theme wird über eigene Tokens gesetzt. Blau ist keine primäre Farbe.

## 2. Tokens

Gesetzte Werte in `frontend/src/styles.css`. Schrift ist IBM Plex Sans, 400/500/600, Latin und Latin Extended. Icons kommen aus lucide-react. Kein Bootstrap, kein Blau als Primärfarbe.

| Token | Wert |
| --- | --- |
| Fläche `--bg` | `#141618` |
| Fläche angehoben `--bg-raised` | `#1b1e22` |
| Karte `--surface` | `#23272c` |
| Fläche 2 `--surface-2` | `#2b3036` |
| Fläche 3 `--surface-3` | `#343a41` |
| Text `--text` | `#f3efe6` |
| Text gedämpft `--text-muted` | `#c2bbb0` |
| Text leise `--text-faint` | `#9c968c` |
| Linie | `rgba(243, 239, 230, 0.08)` und `0.16` |
| Akzent `--accent` | `#c6a15a`, Text darauf `#1c160c` |
| Online `--online` | `#9bb58a` |
| Warnung `--warning` | `#d4b56e` |
| Fehler `--danger` | `#e0a59b` |
| Radius | 12 px, klein 8 px |
| Sidebar / Header | 248 px, eingeklappt 76 px / 64 px |
| Fokus | 2 px Amber, Offset 2 px |

Kontrast: Primärtext auf `#141618` und Amber-Text `#1c160c` auf `#c6a15a` sind die beiden Lesepfade für Handlung und Inhalt. Statusfarben stehen als Punkt und kurze Zeile, nicht als große Fläche.

## 3. Zwei Bereiche, ein System

**Control Center** ist die Seite nach dem Login: Realm, Instanzkarten, Benutzer, globale Einstellungen.

**Workspace** zeigt im Header dauerhaft den Instanznamen, den Verbindungsstatus, den letzten Sync und eine Aktion zurück zum Control Center. Darunter liegen Dashboard, Karte, Höfe, Felder, Fahrzeuge, Tiere, Lager, Preise, Finanzen, Rechnungen und Connector-Diagnose, soweit die Rolle das erlaubt.

Sidebar und Header bleiben in beiden Bereichen dieselben Bausteine. Der Kontextstreifen unterscheidet sie.

## 4. Instanzkarte

Hierarchie von oben nach unten:

1. Anzeigename, optionales Icon
2. Statuspunkt online, offline oder unbekannt
3. Connector verbunden oder nicht, letzter Kontakt
4. zwei bis vier Kennzahlen, nur mit Zeitstempel, sonst „Nicht verfügbar“
5. eine Zeile für Warnung oder fehlende Einrichtung
6. primäre Aktion „Workspace öffnen“
7. sekundäre Aktion „Einstellungen“
8. weiteres im Menü: neu koppeln, Paket herunterladen, Verbindung prüfen, deaktivieren

Karten sind klickbar und führen in den Workspace. Das Menü enthält in Phase 2 nur Aktionen, die den Prototyp-Zustand ändern, oder einen Eintrag, der sichtbar „noch nicht verfügbar“ sagt. Keine toten Buttons.

Demo-Karten tragen ein Badge „Demo“.

Viele Karten: Suche, Filter, Sortierung. Weite Displays als Raster, schmale Displays eine Karte unter der anderen.

## 5. Seiten des Prototyps

1. Login
2. Control Center mit mehreren Karten
3. Instanz anlegen und Kopplungsansicht
4. Benutzer mit Workspace-Zuweisung und Rollen
5. Workspace-Dashboard
6. Fahrzeuge oder Felder mit Suche, Filter, Detail
7. Workspace-Einstellungen und Connector-Status

Zusätzliche Zustände, irgendwo im Fluss sichtbar: Laden, leere Liste, Fehler, offline, ungekoppelt, fehlende Kennzahl, keine Berechtigung.

Formulare zeigen Validierung, einen Ladezustand der primären Aktion und eine Fehlermeldung.

## 6. Akzeptanz für die erste UX-Iteration

- Control Center und Workspace sind unterscheidbar und nutzen dieselben Tokens.
- Sidebar und Header sind konsistent.
- Karten priorisieren Status und die zwei benannten Aktionen.
- Desktop und eine schmale Breite sind bedienbar, ohne abgeschnittene Beschriftungen und ohne horizontale Seite.
- Interaktive Elemente tun etwas oder sind als noch nicht verfügbar gekennzeichnet.
- Loading, Error, Empty, Offline und Demo sind gestaltet.
- Kein blaues Bootstrap-Standardtheme, keine gemischten Icon-Sätze, keine Chart-Serie, die wie ein echter Server aussieht und Demo-Daten sind.

Phase 2 endet mit Build, Lint und einer Browserprüfung beider Breiten. Das Ergebnis steht im Prüfprotokoll.

## 6a. Prüfprotokoll

**12. Oktober 2026.** Prototyp unter `frontend/`, Dev-Server `npm run dev`. Geprüft in Microsoft Edge, headless, 1280×800 und 390×844. Die Login-Zustände wurden zusätzlich im IDE-Browser gesehen: leere Felder, Ladezustand „Anmeldung läuft“, Fehler nach dem Passwort `wrong-password`.

Bestanden:

- Login: Validierung, Ladezustand, Fehler, danach erfolgreiche Demo-Anmeldung.
- Instanzkarten für online, offline, nicht gekoppelt, Warnung und Demo. Fehlende Kennzahlen zeigen „Nicht verfügbar“.
- Suche ohne Treffer, Filter zurücksetzen, Fehlerzustand mit erneutem Versuch, Aktualisieren mit Skelett.
- Verbindung prüfen: offline bleibt offline, online schreibt den letzten Kontakt neu.
- „Paket herunterladen“ öffnet den Hinweis, dass das Paket noch nicht existiert.
- Instanz anlegen: zu kurzer Name, danach einmaliger Code mit Präfix `DEMO-`. Der neue Workspace ist ungekoppelt.
- Benutzer anlegen. Sprache wechselt zwischen Deutsch und Englisch.
- Workspace: Hinweis, dass der Feed keinen Kontostand je Hof liefert. Fahrzeuge mit Suche, Detail und leerer Liste. Ausrichtung ist „Nicht verfügbar“.
- Feed-URL: ungültiger Wert wird abgewiesen, eine https-URL bleibt lokal, es geht kein Request hinaus.
- Ansicht als Jonas Berger: fremder Workspace, Workspace-Einstellungen und Benutzer zeigen „Keine Berechtigung“.
- Bei 390 px bleibt die Seitenbreite auf Login, Instanzverwaltung und Fahrzeugen innerhalb des Viewports. Die Sidebar lässt sich öffnen und schließen. Fahrzeuge werden zu Karten.
- `npm run lint` ohne Befund. `npm run build` erfolgreich.

Nach der ersten Ansicht angepasst: die Aktionszeile der Karten umbricht, die schmale Sidebar hat einen eigenen Schließen-Button, die Schrift lädt nur Latin und Latin Extended.

Der Prototyp schreibt Workspace-Änderungen in `sessionStorage`. Kopplungscodes bleiben nur im Speicher und verschwinden beim Verlassen der Anzeige. Es gibt kein Backend.

## 7. No-Gos

Generisches ungeändertes Admin-Theme, dominantes Blau, mehrere Designsysteme, leere Riesenflächen, überladene Mini-Widgets, Neon, Glaseffekte, Emoji-Icons, Buttons ohne Wirkung, erfundene Live-Charts, Formulare ohne Validierung, eine Startseite aus einer Überschrift und drei Standardkarten.
