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

## Geräte-Zeitpläne (Anzeige only)

- **Gen2** (z. B. Dual Water Control 2814): Zeitpläne liegen am Gerät und werden in der **Gardena-App** bearbeitet.
- IPS zeigt den Stand beim Poll in Variable **„Geräte-Zeitpläne“** und in der **Zeitplan-Kachel** (read-only).
- Beim Geräte-Scan wird für Gen2-Ventile automatisch eine Zeitplan-Instanz angelegt (Name: „… Zeitplan“)
- **Gen1** (ältere Water Control, Power): Binärformat — ebenfalls read-only in IPS. Power: optional **Sonnen-Zeitplan löschen**
- Valve-Kachel = Ventilname + Dauer-Auswahl + Start/Stop; Zeitplan und Wasserverbrauch haben eigene Kacheln

## Manuelle Bewässerung

- Auf der Valve-Kachel Dauer wählen (1–45 Min) und Start tippen. Gen2 sendet die Dauer an das Gerät; das Ventil schließt nach Ablauf selbst.
- Stop beendet vorzeitig.

## Hinweise

- `websocketd` ist experimentell; nach Firmware-Updates ggf. erneut aktivieren
- Dual Water Control = **eine** Valve-Instanz mit Ventil A und B
- Geräte hängen **logisch** am Gateway (`GatewayInstanceID` + Objektbaum-Parent), ohne Symcon-Datenfluss/Splitter
- Für Literzählung je Ausgang ein **Durchfluss-Preset** setzen — sonst erscheint 0 Liter trotz laufendem Ventil
- **Debug:** Am Gateway „Debug-Log aktiv“ einschalten und **Übernehmen**, dann „Debug-Test“ — Variable `Debug-Log` + Formularvorschau zeigen WSS-/Kommando-Details; Button „Debug-Log leeren“
- Pump / Mäher: noch nicht im MVP

## Protokoll (Kurz)

- URL: `wss://HOST:8443`
- Auth: HTTP Basic `_ : PASSWORD`
- Discovery: JSON-Array mit `op=read` auf `devices` für `lemonbeatd` und `lwm2mserver`
- Gen2-Ventil: `execute` auf `actuator/{id}/start|stop` mit Payload `as: ["18", "<seconds>"]`
- Gen2-Zeitpläne: IPS liest nur (`schedule/*`); Schreiben erfolgt über die Gardena-App
