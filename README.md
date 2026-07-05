# ipsmodule

Gemeinsames Cursor-/Git-Repository für **IP-Symcon-Module** (Bibliothek **MM-Modules**).

> Eine `library.json` kann mehrere Module enthalten: Symcon erkennt jeden Unterordner unter `mm/` mit `module.json` automatisch als Modul.

## Enthaltene Module

| Modul | Ordner | Beschreibung |
|-------|--------|--------------|
| **Pixoo Energy Viewer** | `mm/PIXOOEnergyViewer/` | SMA/Home-Manager-Werte auf Divoom Pixoo-Display |
| **WifiWhirl Whirlpool** | `mm/WifiWhirl/` | Bestway-Whirlpool per HTTP (Polling), kein MQTT |

## Verzeichnisstruktur

```
ipsmodule/
├── locale.json
├── mm/
│   ├── library.json           ← Bibliothek „MM-Modules“ (GUID {078F2CCC-…})
│   ├── PIXOOEnergyViewer/
│   └── WifiWhirl/
├── standalone/                ← Python-CLI für Pixoo (ohne Symcon)
│   ├── README.md
│   ├── requirements.txt
│   └── …
└── README.md
```

## IP-Symcon

1. *Kern Instanzen → Modules* → Git-URL oder lokalen Pfad zu diesem Repo (`mm/library.json` muss erreichbar sein)
2. Bibliothek **„MM-Modules“** installieren
3. *Instanz hinzufügen* → Hersteller **MM-Modules** → **Pixoo Energy Viewer** oder **WifiWhirl Whirlpool**

### Pixoo Energy Viewer

- Hersteller-Menü: **MM-Modules**
- Liest IPS-Statusvariablen (SMA Home Manager / Wechselrichter) und aktualisiert ein Pixoo-Display
- Details: Modul-Konfiguration in Symcon; optional Python ohne Symcon: [standalone/README.md](standalone/README.md)

### WifiWhirl Whirlpool

- Hersteller-Menü: **MM-Modules**
- Standalone-Instanz (kein Parent)
- **Host** = IP oder `wifiwhirl-xxxxxx.local`
- In der WifiWhirl-Web-UI: **HTTP-Polling aktivieren** (Firmware v1.2.0+)
- Endpunkte: `GET /getpolldata/`, `POST /sendcommand/`

#### IPS-Automatisierung (Pumpe / Heizung / PV)

Im Modul-Konfigurationspanel **Automatisierung (IPS)**:

1. **Automatisierung aktiv** einschalten
2. Regeln anlegen (List): Typ **Pumpe** oder **Heizung**, Wochentage, Start/Ende (`Start ≤ Uhrzeit < Ende`)
3. Heizung: **Ziel °C** (20–40), optional **PV** pro Regel (nur bei PV-Überschuss)
4. **Variable: PV-Überschuss (W)** wählen (beliebige IPS-Variable in Watt, z. B. Überschuss aus dem Energiemonitor)
5. PV-Parameter: Schwellwert **2500 W** (≈ 2,5 kW Heizung), Einschalt-/Ausschalt-Verzögerung, Hysterese

**Manuelles Schalten** (Pumpe, Heizung, Ein/Aus, Zieltemperatur) pausiert die **aktuelle Regel** bis zum Ende ihres Zeitfensters. Button **Manuelle Pause aufheben** setzt die Automatisierung sofort fort.

**Beispiel:** Mo–Fr 08:00–20:00 Pumpe; Mo–Fr 10:00–18:00 Heizung 38 °C mit PV-Freigabe.

Statusvariablen: `Automatisierung Status`, `Automatisierung PV-Überschuss`, `Automatisierung PV-Freigabe`, …

```bash
curl "http://192.168.1.100/getpolldata/"
curl -X POST "http://192.168.1.100/sendcommand/" -H "Content-Type: application/json" -d "{\"CMD\":4,\"VALUE\":true}"
```

## Versionierung

| Ebene | Datei | Felder |
|-------|-------|--------|
| Bibliothek | `mm/library.json` | `version`, `build` |
| Modul | `mm/*/module.php` | `MODULE_VERSION`, `MODULE_BUILD` |

Aktuelle Bibliothek: **1.2 (Build 37)**

## Frühere Projektpfade

Dieses Repo vereint:

- `ipsymcon-sma-divoom` (PIXOO + standalone)
- `ipsymcon-wifiwhirl` (WifiWhirl)

Neue Entwicklung bitte nur noch unter **`ipsmodule`**.

## Kompatibilität

- IP-Symcon 7.1+
- WifiWhirl: HTTP-Polling empfohlen (v1.2.0+)
