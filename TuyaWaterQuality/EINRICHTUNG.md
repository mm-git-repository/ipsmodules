# Yieryi Wasserqualität — Einrichtungsanleitung

Anleitung für das IP-Symcon-Modul **Yieryi Wasserqualität** (`TuyaWaterQuality`) der Bibliothek **MM-Modules**.

Das Modul liest **pH, ORP, EC, TDS und Wassertemperatur** per **LAN** (Port 6668) oder **Cloud**. Für **alle Messwerte aus der Cloud** wird die **Tuya IoT Developer Cloud** (Access ID/Secret) benötigt; die QR-Kopplung reicht nur für TDS/Temperatur.

---

## Wichtig vorab

| Thema | Erklärung |
|-------|-----------|
| **Primärer Weg** | **Tuya-Kopplung** im Instanz-Formular: User Code → QR scannen → Gerät übernehmen → Local Key + Device ID automatisch |
| **Local Key in der App** | In **Tuya Smart** / **Smart Life** wird der Key **nicht angezeigt**. Das ist normal — Kopplung oder Cloud-Login holen ihn. |
| **Keine LAN-Einstellung in der App** | Bei **YINMIK** / **szjcy**-Sensoren fehlen oft Geräte-Einstellungen für LAN — **kein Blocker**, kein „Gerät aus App entfernen“ nötig |
| **Datenquelle** | **LAN, sonst Cloud** (Standard): zuerst Port 6668, dann Cloud (IoT-API bevorzugt, sonst QR-Sharing) |
| **Alle Cloud-Werte (pH/ORP/EC)** | **IoT Access ID + Secret** aus [iot.tuya.com](https://iot.tuya.com) — Panel „Tuya IoT Cloud“ |
| **Cloud-Session** | Mit **„Cloud-Session behalten“** (Standard) bleibt die Session nach „Gerät übernehmen“ für den Fallback aktiv — bei Ablauf QR erneut scannen |
| **Internet** | QR-Login und Cloud-Abfragen (HTTPS zu Tuya); reines LAN ohne Cloud möglich, wenn Port 6668 antwortet |
| **Linkify-Meldung** | Warnung *„The plugin Linkify cannot be loaded“* kommt vom **Browser/Adblocker**, nicht vom Modul |

---

## 1. Sensor vorbereiten

1. Sensor in **Tuya Smart** oder **Smart Life** einrichten (Gerät **in der App behalten**).
2. Nur **2,4-GHz-WLAN** verwenden.
3. Prüfen, dass der Sensor in der App **online** ist.
4. Im Router eine **feste IP** (DHCP-Reservierung) vergeben — hilfreich, falls die Cloud keine IP liefert.

---

## 2. Modul in IP-Symcon installieren

1. **Kern Instanzen → Module** → Bibliothek **MM-Modules** laden.
2. **Instanz hinzufügen** → **MM-Modules** → **Yieryi Wasserqualität**.
3. Nach Updates Bibliothek neu laden und Instanz **Übernehmen**.

---

## 3. Tuya-Kopplung im Formular (empfohlen)

Expansion **„Tuya-Kopplung (einmalig)“** in der Instanz:

| Schritt | Aktion |
|---------|--------|
| 1 | **User Code** aus Tuya Smart: *Ich → Einstellungen → Konto und Sicherheit → User Code* eintragen → **Übernehmen** |
| 2 | **QR-Code anzeigen** → Browser mit QR-Bild öffnet sich |
| 3 | **Auf Anmeldung warten** (bis zu ~1 Minute Wartezeit pro Klick) |
| 4 | **Gerät aus Cloud-Liste** wählen (z. B. YINMIK Water Quality Tester) |
| 5 | **Cloud-Session behalten** ✓ (für Cloud-Fallback) |
| 6 | **Gerät übernehmen & speichern** → Device ID, Local Key, Host, DP-Mapping werden gesetzt |
| 7 | **Übernehmen** → **Jetzt aktualisieren** |

Optional:

- **LAN-Scan (IP)** — UDP-Discovery oder TCP-6668-Hinweis
- **Cloud-Session beenden** — Token löschen (nur wenn kein Cloud-Fallback gewünscht)

### Datenquelle

| Einstellung | Bedeutung |
|-------------|-----------|
| **Nur LAN** | Nur Port 6668 (schnell, offline-fähig) |
| **Nur Cloud** | Nur Tuya-Cloud (Session muss gültig sein) |
| **LAN, sonst Cloud** | Standard — bei fehlender Tuya-Antwort auf 6668 automatisch Cloud |

Variable **Datenquelle (letzte Abfrage)** zeigt `LAN`, `Cloud-IoT` oder `Cloud` (QR-Sharing).

---

## 3b. Tuya IoT Cloud — alle Messwerte (pH, ORP, EC)

Die **QR-Kopplung** holt Local Key und liefert im Betrieb nur **TDS + Temperatur**. Für **pH/ORP/EC aus der Cloud**:

### Schritt 1: Projekt auf iot.tuya.com

1. [Tuya IoT Platform](https://iot.tuya.com) → Cloud-Projekt anlegen (Region **EU**, wenn die App in EU läuft).
2. **Cloud → Link App Account** — dieselbe Smart-Life/Tuya-App verknüpfen wie am Sensor.
3. Unter **API** die Dienste **IoT Core** / Gerätestatus freischalten.
4. **Access ID** und **Access Secret** notieren.

### Schritt 2: DP-Instruction-Modus (wichtig für YINMIK)

Im IoT-Portal beim Produkt **u5xgcpcngk3pfxb4** (oder Support-Ticket):

- **Control Instruction Mode** von *Standard Instruction* auf **DP Instruction** stellen.
- Konfiguration synchronisieren (kann einige Stunden dauern).

Ohne DP Instruction liefert auch die IoT-API oft nur `tds_in` und `temp_current` — wie die App-interne Standard-Schnittstelle.

### Schritt 3: Im IP-Symcon-Modul

Expansion **„Tuya IoT Cloud (alle Messwerte)“**:

| Feld | Wert |
|------|------|
| **IoT Access ID** | aus dem Cloud-Projekt |
| **IoT Access Secret** | aus dem Cloud-Projekt |
| **IoT Rechenzentrum** | **EU (West)** — muss zur App-Region passen |

→ **Übernehmen** → **IoT-Cloud testen** — sollte u. a. `ph`, `orp_value`, `conductivity_value` in den Status-Codes zeigen.

Im Betrieb: Bei **Nur Cloud** oder **LAN, sonst Cloud** wird zuerst die **IoT-API** genutzt; QR-Sharing nur als Fallback (TDS/Temp).

---

### YINMIK / szjcy DP-Mapping (automatisch)

Bei Product ID `u5xgcpcngk3pfxb4` oder Kategorie `szjcy`:

```json
{"tds":{"dp":1,"scale":0.001},"temperature":{"dp":2,"scale":0.1},"ph":{"dp":10,"scale":0.01},"ec":{"dp":11,"scale":1},"orp":{"dp":12,"scale":1}}
```

**Cloud-Hinweis (YINMIK):** QR-Sharing liefert nur TDS/Temp — für pH/ORP/EC siehe Abschnitt **3b IoT Cloud**.

---

## 4. Alternative: Local Key manuell

Falls die Formular-Kopplung nicht funktioniert:

### Methode A: Home Assistant + tuya-device-sharing-sdk

Mit offizieller HA-Tuya-Integration und Python-SDK ([tuya-device-sharing-sdk](https://github.com/tuya/tuya-device-sharing-sdk)) `local_key` auslesen und in IP-Symcon eintragen.

### Methode B: tinytuya scan (nur IP)

```powershell
pip install tinytuya
python -m tinytuya scan
```

Liefert **IP** und **Device ID**, aber **keinen Local Key**.

Referenztest **vom IP-Symcon-Server** (nicht vom PC, falls anderes Netz):

```python
import tinytuya
d = tinytuya.Device('DEVICE_ID', 'GERÄTE_IP', 'LOCAL_KEY', dev_type='device22')
d.set_version(3.3)
d.set_dpsUsed({"1": None, "2": None})
print(d.status())
```

| tinytuya-Ergebnis | Konsequenz |
|-------------------|------------|
| Auch 0 Bytes/Timeout | LAN am Gerät praktisch tot → **Cloud-Fallback** nutzen |
| JSON mit DPS | LAN sollte funktionieren — Debug-Log mit Modul vergleichen |

### Methode C: tuya-uncover (veraltet / oft defekt)

[github.com/blakadder/tuya-uncover](https://github.com/blakadder/tuya-uncover) — bei vielen Accounts inzwischen **nicht mehr zuverlässig**.

### Methode D: Tuya IoT Cloud

Nur wenn Rechenzentrum zur App-Region passt: [iot.tuya.com](https://iot.tuya.com) → App verknüpfen → Device Details → `local_key`.

---

## 5. Manuelle Werte in IP-Symcon

| Feld | Wert |
|------|------|
| **Aktiv** | ✓ |
| **Datenquelle** | **LAN, sonst Cloud** (empfohlen bei YINMIK) |
| **Geräte-IP (LAN)** | Feste IP / Scan / Cloud |
| **Tuya Device ID** | z. B. `bf304e1f1e35c32232syye` |
| **Tuya Local Key** | aus Kopplung oder HA-Script |
| **Tuya-Protokollversion** | Meist **3.3** (YINMIK oft device22) |
| **Aktualisierungsintervall** | z. B. 60 s (Minimum 15) |
| **DP-Mapping** | Standard (8-in-1) oder YINMIK (siehe oben) |

→ **Übernehmen** → **Jetzt aktualisieren**

### Variablen (Auswahl)

| Variable | Bedeutung |
|----------|-----------|
| pH / ORP / EC / TDS / Wassertemperatur | Messwerte |
| Erreichbar | Verbindung OK (LAN oder Cloud online) |
| Datenquelle (letzte Abfrage) | `LAN` oder `Cloud` |
| Roh-DPS (Debug) | Rohe Tuya-Datenpunkte |

---

## 6. Fehlerbehebung

| Symptom | Maßnahme |
|---------|----------|
| QR-Login schlägt fehl | User Code prüfen, Internet/Firewall, erneut scannen |
| `OpenSSL fehlt` | PHP-OpenSSL auf Symcon-Server aktivieren |
| `Keine Cloud-Session` | QR-Login erneut; **Cloud-Session behalten** aktiv lassen |
| Cloud OK, aber nur TDS/Temp | IoT Access ID/Secret eintragen + **DP Instruction** auf iot.tuya.com; „IoT-Cloud testen“ |
| IoT-Cloud Token fehlgeschlagen | Region (EU/US), Access ID/Secret, App-Verknüpfung prüfen |
| TCP 6668 offen, 0 Bytes Antwort | Normal bei manchen Firmwares → **LAN, sonst Cloud** |
| Kein UDP-Scan, Ping OK | UDP ≠ Ping — Host manuell setzen |
| Werte leer | **Roh-DPS** prüfen, DP-Mapping anpassen |
| Sensor neu gekoppelt | **Gerät übernehmen** erneut ausführen |

### Standard DP-Mapping (PH-W218 / 8-in-1)

```json
{"ph":{"dp":106,"scale":0.01},"temperature":{"dp":8,"scale":0.1},"tds":{"dp":111,"scale":1},"ec":{"dp":116,"scale":1},"orp":{"dp":131,"scale":1}}
```

---

## 7. Ablauf (Übersicht)

```
Tuya Smart (Sensor online, in App behalten)
        │
        ├─► Formular QR-Kopplung ──► Device ID + Local Key + Mapping + Session
        │
        └─► LAN-Scan / Router-IP ──► Host
                    │
                    ▼
        IP-Symcon: Yieryi Wasserqualität (Übernehmen)
                    │
                    ▼
        Abfrage alle 15–60 s: LAN (6668) → optional Cloud-Fallback
```

---

## 8. Optional: Pool Steuerung

Mit **Pool Steuerung** (`PoolControl`) die konfigurierte **Yieryi-Wasserqualität**-Instanz als Sensor-Quelle wählen.

---

*Modul: `TuyaWaterQuality` · Präfix: `TWQT` · Build 17 · Bibliothek: MM-Modules*
