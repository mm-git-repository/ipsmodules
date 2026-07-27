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
| Gardena Smart Gateway | WSS-Client, Discovery, Scan-Button, Zeitplan-Übersicht (read-only) |
| Gardena Smart Valve | Water / Dual / Pipeline — Steuerung + IPS-Zeitpläne + Web-Kachel |
| Gardena Smart Power | Power Adapter — Schalten + IPS-Zeitpläne + Web-Kachel |
| Gardena Smart Sensor | Sensor / Sensor II — Messwerte + Web-Kachel |

## Hinweise

- `websocketd` ist experimentell; nach Firmware-Updates ggf. erneut aktivieren
- Dual Water Control = **eine** Valve-Instanz mit Ventil A und B
- Geräte hängen **logisch** am Gateway (`GatewayInstanceID` + Objektbaum-Parent), ohne Symcon-Datenfluss/Splitter
- Geräte-App-Zeitpläne werden **nur gelesen** angezeigt; IPS-Zeitpläne am Child sind der empfohlene Master
- Pump / Mäher: noch nicht im MVP

## Protokoll (Kurz)

- URL: `wss://HOST:8443`
- Auth: HTTP Basic `_ : PASSWORD`
- Discovery: JSON-Array mit `op=read` auf `devices` für `lemonbeatd` und `lwm2mserver`
- Gen2-Ventil: `execute` auf `actuator/{id}/start|stop` mit Payload `as: ["18", "<seconds>"]`
