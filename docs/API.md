# API

**Status:** Vertragsskizze. Keine Route ist implementiert. Beispiele sind Absicht, keine getesteten Antworten.  
**Stand:** 12. Oktober 2026

## 1. Grundlagen

- Basis: `/api/v1`
- Browser: Cookie-Sitzung, gleiche Origin, CSRF auf schreibenden Aufrufen
- Connector-Bridge: `Authorization: Bearer` mit dem Kopplungsgeheimnis, kein Cookie
- JSON, UTF-8
- Zeiten in UTC, ISO-8601
- Fehler: HTTP-Status, `error_code`, übersetzte `message`, keine Stacktraces in Produktion

```json
{
  "error_code": "workspace_not_found",
  "message": "Workspace nicht gefunden."
}
```

Fremde Workspaces liefern denselben Code wie fehlende, für Benutzer ohne Realm-Admin-Recht.

## 2. Sitzung

| Methode | Pfad | Zweck |
| --- | --- | --- |
| POST | `/api/v1/session` | Login mit E-Mail und Passwort |
| DELETE | `/api/v1/session` | Logout |
| GET | `/api/v1/me` | Profil, Locale, globale Rolle, Mitgliedschaften |

Passwort-Reset folgt in Phase 3 als eigener kleiner Vertrag mit Token-Hash.

## 3. Control Center

| Methode | Pfad | Zweck |
| --- | --- | --- |
| GET | `/api/v1/workspaces` | Kartenliste, Suche, Filter, Sortierung, Pagination |
| POST | `/api/v1/workspaces` | Instanz anlegen |
| GET | `/api/v1/workspaces/{public_id}` | Metadaten und Status |
| PATCH | `/api/v1/workspaces/{public_id}` | Anzeigename, Icon, Deaktivieren |
| DELETE | `/api/v1/workspaces/{public_id}` | Entfernen, nur mit Bestätigungstext und Rolle `workspace_admin` oder `realm_admin` |

Die Liste enthält nur Workspaces, die die Policy erlaubt. Jede Karte liefert gemeldete Kennzahlen oder `null` mit dem Grund `unavailable`. Demo-Workspaces tragen `data_mode: "demo"`.

## 4. Benutzer

| Methode | Pfad | Zweck |
| --- | --- | --- |
| GET, POST | `/api/v1/users` | zentrale Konten, nur Realm-Admin |
| PATCH | `/api/v1/users/{public_id}` | Name, Aktivität, globale Rolle, Locale |
| PUT | `/api/v1/workspaces/{public_id}/members` | Mitgliedschaften dieses Workspace |

Es gibt keinen zweiten Benutzerbestand je Workspace.

## 5. Kopplung und Host-Zugang

| Methode | Pfad | Zweck |
| --- | --- | --- |
| POST | `/api/v1/workspaces/{public_id}/pairing` | neuen einmaligen Code erzeugen, alten ungültig machen |
| GET | `/api/v1/workspaces/{public_id}/connector` | Status, Version, letzter Kontakt, ohne Geheimnis |
| PUT | `/api/v1/workspaces/{public_id}/host-access` | Protokoll und Zugang. Das Geheimnis kommt nur im Request an und geht nicht wieder hinaus |
| POST | `/api/v1/workspaces/{public_id}/host-access/test` | Verbindung prüfen |
| GET | `/api/v1/workspaces/{public_id}/connector-package` | ZIP ohne Geheimnisse |

## 6. Fachdaten

Alle unter `/api/v1/workspaces/{public_id}/...`:

- `farms`
- `vehicles`, `vehicles/{game_object_id}`
- `fields`, `fields/{game_object_id}`
- `animals`, `storages`, `productions`, `prices`
- `finance`
- `invoices`, `invoices/{id}`, `invoices/{id}/events`
- `activity`

Listen: `q`, fachliche Filter, `sort` aus einer Whitelist, `page`, `per_page` mit Obergrenze.

Jedes Objekt enthält `observed_at`. Fehlende Eigenschaften sind `null` und `availability: "unavailable"`, nicht eine erfundene Zahl.

## 7. Zahlungen

`POST /api/v1/workspaces/{public_id}/invoices/{id}/payments`

Header `Idempotency-Key`. Zweiter Aufruf mit demselben Schlüssel liefert denselben Auftrag und führt ihn nicht erneut aus.

Antwort enthält `payment_order_id` und `state`. `state: "confirmed"` ist der einzige Weg zu einer bezahlten Rechnung. Solange der Spielbefehl nicht nachgewiesen ist, antwortet die Route mit `error_code: "game_execution_unavailable"`.

## 8. Connector-Eingang

Für die lokale Bridge, und später für einen nachgewiesenen Lua-HTTPS-Client. Der Web-API-Feed wird nicht vom Browser geholt. Das Backend liest ihn mit dem gespeicherten Code und schreibt dieselben Validierer-Ergebnisse wie einen Batch.

| Methode | Pfad | Zweck |
| --- | --- | --- |
| POST | `/api/v1/connector/batches` | Snapshot, idempotent über `batch_uuid` |
| GET | `/api/v1/connector/commands` | offene Befehle |
| POST | `/api/v1/connector/commands/{id}/result` | Ergebnis zur Auftrags-ID |

Der Dateiabruf-Transport nutzt dieselben Validierer, nur dass das Backend die Datei selbst liest und den Batch intern anlegt. Die Autorisierung ist dann der gespeicherte Host-Zugang plus die Workspace-Bindung, nicht ein vom Browser gesetzter Header.

## 9. Raten und Größe

Login, Kopplung und Bezahlung sind enger begrenzt als Leseabfragen. Snapshot-Bodies haben eine Obergrenze, die in Phase 4 an echten Dateigrößen justiert und hier nachgetragen wird. Übergröße: `413` und `payload_too_large`.
