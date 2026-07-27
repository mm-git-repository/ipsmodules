# Gardena Smart Gateway — Einrichtung

Lokale Anbindung des **GARDENA smart Gateway** (Art. 19005) an IP-Symcon über die experimentelle WebSocket-API (`websocketd`, Port **8443**). Kein MQTT, kein Cloud-Zwang im Normalbetrieb.

## Voraussetzungen

1. Gateway im LAN (z. B. `172.18.1.189`)
2. SSH-Zugang zum Gateway (siehe [smart-garden-gateway-public](https://github.com/husqvarnagroup/smart-garden-gateway-public))
3. WebSocket-Dienst aktivieren:

```txt
touch /etc/enable-websocketd
systemctl restart firewall
systemctl start websocketd
```

4. Passwort = **erste 8 Zeichen** der Geräte-ID auf der Gehäuserückseite
5. Firewall: TCP **8443** vom Symcon-Host zum Gateway freigeben

## Installation

1. Bibliothek **MM-Modules** in IP-Symcon aktualisieren/laden
2. Instanz **Gardena Smart Gateway** anlegen
3. Host, Passwort, Port `8443`, „TLS-Zertifikat ignorieren“ aktivieren
4. **Verbindung testen**, dann **Geräte scannen / anlegen**
5. Child-Instanzen (Valve / Power / Sensor) erscheinen unter dem Gateway mit Gerätenamen

## Module

| Modul | Rolle |
|-------|--------|
| Gardena Smart Gateway | WSS-Client, Discovery, Scan, Zeitpläne, Wasserverbrauch-Statistik |
| Gardena Smart Valve | Water / Dual / Pipeline — Steuerung (Start/Stop), Verbrauchsschätzung, Steuerungs-Kachel |
| Gardena Smart Valve Zeitplan | Zeitplan-Editor-Kachel (verknüpft mit Valve-Instanz; Gen2 speichern/laden) |
| Gardena Smart Power | Power Adapter — Schalten, Geräte-Zeitpläne read-only (Gen1) + Web-Kachel |
| Gardena Smart Sensor | Sensor / Sensor II — Messwerte + Web-Kachel |

## Wasserverbrauch (Schätzung)

Kein physikalischer Literzähler nötig. Verbrauch = **Öffnungsdauer × konfigurierte l/h**.

1. Am **Gateway** Presets in der Liste **„Durchfluss-Presets“** pflegen (Basis-Presets sind vorbefüllt und änderbar; Button „Basis-Presets wiederherstellen“ setzt zurück)  
2. Am **Valve** je Ausgang A/B nur das Preset wählen und speichern — l/h und Bezeichnung kommen aus dem Preset, die Zählung ist damit aktiv  
3. Öffnen per IPS, App oder Geräte-Zeitplan wird mitgezählt (Poll + Sofort-Tracking bei IPS-Start/Stop)  
4. Zähler: heute / Woche / Jahr / Gesamt (+ laufende Session)  
5. Gateway-Visualisierung und Variable `UsageOverview` zeigen die Statistik (nur Geräte mit aktiven Ausgängen)

Reset: Button **Verbrauchszähler zurücksetzen** am Valve (alle Perioden inkl. Gesamt).

## Geräte-Zeitpläne (Gateway/Cloud als Master)

- **Gen2** (z. B. Dual Water Control 2814): Zeitpläne liegen am Gerät. IPS liest sie beim Poll und kann sie per WSS **`write`** auf `schedule/{slot}/*` zurückschreiben.
- Bearbeitung in **Instanz-Konfiguration** (Liste „Geräte-Zeitpläne“, +/−) oder **Zeitplan-Kachel** (`Gardena Smart Valve Zeitplan`) → Button **An Gerät speichern** / **Vom Gerät laden**
- Beim Geräte-Scan wird für Gen2-Ventile automatisch eine Zeitplan-Instanz angelegt (Name: „… Zeitplan“)
- Maximal **36 Einträge** (wie Gardena-App / Dual Water Control); entfernte Einträge werden am Gerät geleert
- Nach dem Speichern: Rücklesen vom Gerät; Abweichungen werden als Warnung gemeldet
- **Gen1** (ältere Water Control, Power): Binärformat — IPS zeigt read-only; Bearbeitung nur in der Gardena-App. Power: optional **Sonnen-Zeitplan löschen** (leeres `sun_schedule_config`)
- Valve-Kachel = nur Ventilname + Start/Stop; Zeitplan und Wasserverbrauch haben eigene Kacheln (Valve-Zeitplan bzw. Gateway)

### PoC (Gen2, bestätigt)

Am Dual 2814 funktioniert z. B.:

```json
{
  "op": "write",
  "entity": {
    "device": "<deviceId>",
    "service": "lwm2mserver",
    "path": "schedule/0/start_offset_seconds"
  },
  "payload": { "vi": 21600 }
}
```

Änderungen überleben Gateway-Reconnect und sind in der App sichtbar.

## Hinweise

- `websocketd` ist experimentell; nach Firmware-Updates ggf. erneut aktivieren
- Dual Water Control = **eine** Valve-Instanz mit Ventil A und B
- Geräte hängen **logisch** am Gateway (`GatewayInstanceID` + Objektbaum-Parent), ohne Symcon-Datenfluss/Splitter
- Parallelbearbeitung in IPS und Gardena-App kann Pläne überschreiben — UI zeigt „Zuletzt gespeichert von IPS“
- **Debug:** Am Gateway „Debug-Log aktiv“ einschalten und **Übernehmen**, dann „Debug-Test“ — Variable `Debug-Log` + Formularvorschau zeigen WSS-/Kommando-Details; Button „Debug-Log leeren“
- Pump / Mäher: noch nicht im MVP

## Protokoll (Kurz)

- URL: `wss://HOST:8443`
- Auth: HTTP Basic `_ : PASSWORD`
- Discovery: JSON-Array mit `op=read` auf `devices` für `lemonbeatd` und `lwm2mserver`
- Gen2-Ventil: `execute` auf `actuator/{id}/start|stop` mit Payload `as: ["18", "<seconds>"]`
- Gen2-Zeitplan: `write` auf `schedule/{id}/actuator`, `start_offset_seconds`, `end_offset_seconds`, `repetition_type`, `repetition_value`, …
