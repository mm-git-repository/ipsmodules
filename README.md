# MM-Modules — IP-Symcon Bibliothek

Gemeinsames Git-Repository für selbst entwickelte **IP-Symcon-Module** unter der Bibliothek **MM-Modules**.

Die Bibliothek bündelt mehrere Gerätemodule in einem Repo: Symcon erkennt jeden Unterordner unter `mm/` mit eigener `module.json` automatisch als Modul. Beim Hinzufügen einer Instanz erscheinen alle Module unter dem gemeinsamen Hersteller **MM-Modules**.

---

## Enthaltene Module

| Anzeigename | Ordner | Kurzbeschreibung |
|-------------|--------|------------------|
| **Pixoo Energy Viewer** | `mm/PIXOOEnergyViewer/` | Energiedaten aus IPS-Variablen auf ein Divoom Pixoo-64 Display |
| **WifiWhirl Whirlpool** | `mm/WifiWhirl/` | Bestway LAY-Z-SPA (WifiWhirl) per HTTP — Polling, Steuerung, IPS-Automatisierung |
| **Yieryi Wasserqualität** | `mm/TuyaWaterQuality/` | Yieryi / Tuya 7-in-1 Wasserqualitätssensor (pH, ORP, EC, TDS) per lokalem Tuya-Protokoll |
| **Pool Steuerung** | `mm/PoolControl/` | Zentrale Pool-Logik: Grenzwerte, Alarme, Dosierungsempfehlungen (Bayrol Miami Outdoor), pH-Sync zu WifiWhirl |
| **Gardena Smart Gateway** | `mm/GardenaSmartGateway/` (+ Valve/Power/Sensor) | Lokales Gardena-Gateway per WebSocket — Bewässerung, Power, Sensor |

**Aktuelle Version:** Bibliothek **1.4 (Build 57)** · Gardena Smart Gateway **1.0 (Build 2)**

---

## Installation in IP-Symcon

1. **Module verwalten** (*Kern Instanzen → Modules*) öffnen
2. Bibliothek hinzufügen — per **Git-URL** oder **lokalem Pfad** zu diesem Repository  
   (Symcon muss `mm/library.json` finden können)
3. Bibliothek **MM-Modules** installieren bzw. aktualisieren
4. **Instanz hinzufügen** → Hersteller **MM-Modules** → gewünschtes Modul wählen
5. Konfiguration ausfüllen und mit **Übernehmen** speichern

Nach einem Update: Bibliothek neu laden, betroffene Instanz öffnen und erneut **Übernehmen**, damit neue Variablen und Einstellungen angelegt werden.

---

## Pixoo Energy Viewer

Zeigt Verbrauch, Erzeugung und Netzleistung auf einem **Divoom Pixoo-64**. Die Werte kommen aus frei wählbaren IPS-Variablen (typisch: SMA Home Manager, Wechselrichter-Strings).

### Einrichtung

- Sechs Quellvariablen im Modul-Formular zuweisen (Real Power ±, zwei Wechselrichter mit je zwei Strings)
- Pixoo-IP und Aktualisierungsintervall konfigurieren
- Optional: Day-Ahead-Strompreis (SMARD) auf dem Display

### Alternative ohne IP-Symcon

Der Ordner [`standalone/`](standalone/README.md) enthält ein **Python-Programm**, das SMA-Daten direkt per Speedwire/Multicast liest und auf den Pixoo schreibt — ohne Symcon-Installation. Nützlich für Tests oder einen dedizierten Mini-PC am Display.

---

## WifiWhirl Whirlpool

Anbindung eines **WifiWhirl-Moduls** (Bestway LAY-Z-SPA) über das lokale HTTP-API. **Kein MQTT-Broker** erforderlich.

### Voraussetzungen

| Punkt | Details |
|-------|---------|
| Hardware | WifiWhirl-Modul im Whirlpool, im gleichen LAN wie IP-Symcon |
| Firmware | **v1.2.0+** empfohlen |
| Web-UI | **HTTP-Polling** aktivieren (Einstellung in der WifiWhirl-Oberfläche) |
| IP-Symcon | Instanz **WifiWhirl Whirlpool**, Feld **Host** = IP oder `wifiwhirl-xxxxxx.local` |

### API-Endpunkte

| Methode | Pfad | Zweck |
|---------|------|--------|
| GET | `/getpolldata/` | Vollständiger Gerätestatus (STATES, TIMES, OTHER) |
| POST | `/sendcommand/` | Steuerbefehle (`CMD`, `VALUE`) |
| GET | `/getstates/` | Fallback: Schalter-Status |
| GET | `/gettemps/` | Fallback: Temperaturen |

### Beim Anlegen der Instanz

Das Modul legt automatisch alle relevanten Variablen an:

- Schalter: Ein/Aus, Filterpumpe, Heizung, AirJet, HydroJet, Tastensperre, …
- Sensoren: Wasser-/Umgebungstemperatur, Energie, Wasserqualität, Laufzeiten, WLAN, …
- Steuerbare Werte: Zieltemperatur, Helligkeit, pH, Chlor, …

Status wird per Timer abgefragt (Standard: 30 Sekunden, mindestens 15).

### Test per Kommandozeile

```bash
# Status abfragen
curl "http://192.168.1.100/getpolldata/"

# Filterpumpe einschalten
curl -X POST "http://192.168.1.100/sendcommand/" \
  -H "Content-Type: application/json" \
  -d "{\"CMD\":4,\"VALUE\":true}"
```

Wichtige CMDs: `0` Zieltemperatur · `3` Heizung (inkl. Pumpe) · `4` Filterpumpe · `22` Ein/Aus

---

## IPS-Automatisierung (WifiWhirl)

Die Automatisierung läuft **in IP-Symcon** im WifiWhirl-Modul — unabhängig von der WifiWhirl-internen Warteschlange oder Smart Schedule in der Web-UI.

### Funktionen

- **Zeitfenster** für Pumpe und Heizung (Wochentage, Start, Ende)
- **Zieltemperatur** pro Heizungs-Regel (20–40 °C)
- **PV-Freigabe** optional pro Heizungs-Regel: Heizung nur bei ausreichend PV-Überschuss (Standard-Schwelle **2500 W** ≈ 2,5 kW)
- **Anti-Flattern:** Einschalt-Verzögerung, Ausschalt-Verzögerung, Hysterese
- **Manuelle Pause:** Manuelles Schalten über Modul-Variablen pausiert die aktuelle Regel bis zum Ende ihres Zeitfensters

### Einrichtung

Im Konfigurationspanel **Automatisierung (IPS)** (ohne erklärende Fließtexte — Details siehe README):

1. **Automatisierung aktiv** einschalten
2. **Pumpen-Zeitpläne** und **Heizungs-Zeitpläne** getrennt anlegen:
   - Wochentage (Mo–So), **Start** und **Ende** (`Start ≤ Uhrzeit < Ende`)
   - Bei Heizung: **Ziel °C**, optional **PV-Freigabe**
3. **Variable: PV-Überschuss (W)** wählen — beliebige IPS-Variable in Watt (keine Kopplung an andere Module im Repo)
4. PV-Parameter anpassen (Empfehlung: Einschalt-Verzögerung 300 s, Ausschalt-Verzögerung 180 s, Hysterese 200 W)

### Beispiel-Konfiguration

| Regel | Mo–Fr | Zeit | Aktion |
|-------|-------|------|--------|
| 1 | ✓ | 08:00–20:00 | Pumpe ein |
| 2 | ✓ | 10:00–18:00 | Heizung 38 °C, nur mit PV |

Außerhalb aller Zeitfenster schaltet die Automatisierung ab. **Zieltemperatur:** Im aktiven Heizungs-Zeitfenster setzt die Automatisierung die Zieltemp. aus dem Zeitplan (Variable **Automatisierung Zieltemp. Soll**). Die Variable **Zieltemperatur** zeigt den aktuellen Wert am Gerät.

Bei manuellem Eingriff über Symcon (Pumpe, Heizung, Ein/Aus, Zieltemperatur) wird die laufende Heizungs-Regel bis zum Fenster-Ende pausiert — dann gilt die manuelle Einstellung (z. B. 35 °C statt 34 °C aus dem Zeitplan). Mit **Manuelle Pause aufheben** wird der Zeitplan sofort wieder aktiv.

### Statusvariablen

| Variable | Bedeutung |
|----------|-----------|
| Automatisierung Status | Textuelle Zusammenfassung (z. B. „Heizung: PV wartet (2100 W)“) |
| Automatisierung PV-Überschuss | Aktuell gelesener PV-Wert (W) |
| Automatisierung PV-Freigabe | Ob PV-Gate offen ist |
| Automatisierung Pumpe/Heizung Soll | Berechneter Soll-Zustand |
| Automatisierung Zieltemp. Soll | Zieltemperatur aus dem aktiven Heizungs-Zeitplan (°C) |

### WebFront: Regeln bearbeiten (HTML-SDK)

Ab Modul-Build 6 stellt die WifiWhirl-Instanz eine **HTML-Kachel** (Symcon HTML-SDK, IPS 7.1+) bereit — mobile-optimierte Tabellen für Pumpen- und Heizungs-Regeln direkt auf der Instanz-Kachel.

**Nach Modul-Update:** Instanz einmal **Übernehmen**, damit die HTML-Darstellung aktiviert wird (`SetVisualizationType(1)`).

#### Einbindung

1. WifiWhirl-Instanz in der **Visualisierung** als Kachel einbinden (nicht einzelne Variablen)
2. Darstellung der Instanz: **HTML** (wird beim Übernehmen gesetzt)
3. Die Kachel zeigt:
   - Schalter **Automatisierung aktiv**
   - Statuszeile (`AutomationStatus`)
   - Zwei Tabellen (Pumpe / Heizung) mit beliebig vielen Zeilen
   - **Speichern** schreibt in die Properties `AutomationPumpRules`, `AutomationHeaterRules`, `AutomationEnabled`

#### PC vs. Mobile

| Oberfläche | Wo |
|------------|-----|
| **PC** | Modul-Konfiguration — native `List`-Tabellen in `form.json` |
| **WebFront / Visualisierung** | HTML-Kachel der Instanz (dieser Editor) |

Beide Oberflächen nutzen dieselben Properties. Keine Slot-Variablen mehr im Objektbaum.

**Hinweis:** Im HTML-Editor können einzelne Wochentage pro Regel gewählt werden (wie im PC-Konfigurationsdialog).

---

## Projektstruktur

```
ipsmodule/
├── README.md
├── locale.json
├── mm/
│   ├── library.json
│   ├── README.md
│   ├── PIXOOEnergyViewer/
│   ├── WifiWhirl/
│   ├── TuyaWaterQuality/
│   ├── PoolControl/
│   ├── GardenaSmartShared/      ← gemeinsame PHP-Libs (kein Modul)
│   ├── GardenaSmartGateway/     ← Parent / Splitter
│   ├── GardenaSmartValve/
│   ├── GardenaSmartPower/
│   └── GardenaSmartSensor/
└── standalone/
```

### Namensgebung in Symcon

| Ebene | Quelle | Beispiel |
|-------|--------|----------|
| Bibliothek | `mm/library.json` → `name` | MM-Modules |
| Hersteller-Menü | `module.json` → `vendor` | MM-Modules |
| Anzeigename | `module.json` → `aliases` | WifiWhirl Whirlpool |
| Klassenname | `module.json` → `name` | WifiWhirl |

---

## Tuya Wasserqualität (Yieryi)

Liest **pH, ORP, EC, TDS und Temperatur** von Tuya/Yieryi-Wasserqualitätstestern per **lokalem LAN-Protokoll** (Port 6668).

### Einrichtung

1. Instanz **Yieryi Wasserqualität** anlegen
2. **Device ID**, **Local Key** und **Geräte-IP** eintragen (Tuya Smart App / tinytuya)
3. Standard-**DP-Mapping** gilt für PH-W218 / 8-in-1 — nach Geräte-Lieferung ggf. anpassen
4. Sensor-Instanz in **Pool Steuerung** verknüpfen

---

## Gardena Smart Gateway

Lokale Steuerung von GARDENA-Geräten über das Smart Gateway (`websocketd` Port 8443). Details: [`mm/GardenaSmartGateway/EINRICHTUNG.md`](mm/GardenaSmartGateway/EINRICHTUNG.md).

### Einrichtung

1. `websocketd` auf dem Gateway aktivieren (SSH)
2. Instanz **Gardena Smart Gateway** anlegen (Host, Passwort = erste 8 Zeichen der Geräte-ID)
3. **Geräte scannen / anlegen** — erzeugt Child-Instanzen (Valve/Power/Sensor)
4. Am Valve/Power: IPS-Zeitpläne und Web-Kachel nutzen

---

## Pool Steuerung

Zentrale Logik für **Bestway Miami Outdoor** (Default 669 l): pH/ORP-Grenzwerte, Alarme, **Dosierungsempfehlungen** (Bayrol Multi-Tab oder Flüssigchlor, Bayrol pH-Pulver in Gramm), optional **pH-Sync** zum WifiWhirl.

### Einrichtung

1. **Yieryi Wasserqualität**-Instanz anlegen und konfigurieren
2. **Pool Steuerung** anlegen → Sensor-Instanz wählen
3. Optional: WifiWhirl-Instanz für pH-Sync
4. Chlor-Empfehlung: Multi-Tab (Standard) oder Flüssigchlor (ml)
5. Heizung wird **nicht** durch Wasserqualität blockiert

**Geplant (noch nicht implementiert):** Zeitsteuerung/PV-Migration aus WifiWhirl, automatische Dosierung.

---

## Versionierung

| Ebene | Datei | Felder |
|-------|-------|--------|
| Bibliothek | `mm/library.json` | `version`, `build` |
| Modul | `mm/*/module.php` | `MODULE_VERSION`, `MODULE_BUILD` |

Nach Code-Änderungen: Build-Nummer erhöhen und Bibliothek in Symcon neu laden.

---

## Kompatibilität

- **IP-Symcon** 7.1+ (Bibliothek), WifiWhirl-Modul nutzt `IPSModuleStrict` (IPS 8.x-kompatibel)
- **WifiWhirl-Firmware** v1.2.0+ mit aktiviertem HTTP-Polling
- **Pixoo Energy Viewer:** Divoom Pixoo-64 im LAN erreichbar

---

## Hinweise

- Die Webhook-Schnittstelle des WifiWhirl hat **keine Authentifizierung** — nur im vertrauenswürdigen LAN einsetzen.
- Die IPS-Automatisierung ersetzt nicht die Smart-Schedule-Funktion in der WifiWhirl-Web-UI; beide können parallel existieren, sollten aber inhaltlich nicht widersprüchlich konfiguriert werden.
- **Umgebungstemperatur** im Modul ist reine Anzeige (Sensor); Einstellung erfolgt in der WifiWhirl-UI oder per Wetterdienst.

---

## Frühere Projekte

Dieses Repository vereint frühere Einzelrepos:

- `ipsymcon-sma-divoom` → Pixoo Energy Viewer + `standalone/`
- `ipsymcon-wifiwhirl` → WifiWhirl Whirlpool

Neue Entwicklung bitte ausschließlich unter **`ipsmodule`**.
