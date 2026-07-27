# Yieryi Wasserqualität — Einrichtungsanleitung

Anleitung für das IP-Symcon-Modul **Yieryi Wasserqualität** (`TuyaWaterQuality`) der Bibliothek **MM-Modules**.

Das Modul liest **pH, ORP, EC, TDS und Wassertemperatur** per **lokalem Tuya-LAN-Protokoll** (Port 6668). Es wird **keine Tuya-Cloud** für den laufenden Betrieb benötigt — aber du brauchst einmalig **Device ID** und **Local Key**.

---

## Wichtig vorab

| Thema | Erklärung |
|-------|-----------|
| **Local Key in der App** | In **Tuya Smart** / **Smart Life** wird der Key **nicht angezeigt**. Das ist normal. |
| **Tuya IoT Cloud** | Nur nötig, wenn du den Key über die offizielle Developer Platform holen willst. Bei Fehler „falsches Rechenzentrum“ siehe [Abschnitt 3](#3-local-key-ohne-tuya-iot-cloud-empfohlen). |
| **Nur lokal** | IP-Symcon und Sensor müssen im **gleichen LAN** sein. Internet wird zum Auslesen **nicht** benötigt. |
| **Linkify-Meldung** | Warnung *„The plugin Linkify cannot be loaded“* in der Symcon-Konsole kommt vom **Browser/Adblocker**, nicht vom Modul. Ausnahme für `127.0.0.1:3777` setzen oder ignorieren. |

---

## 1. Sensor vorbereiten

1. Yieryi-Sensor in **Tuya Smart** oder **Smart Life** einrichten.
2. Nur **2,4-GHz-WLAN** verwenden (kein reines 5-GHz).
3. Prüfen, dass der Sensor in der App **online** ist.
4. Im Router eine **feste IP** (DHCP-Reservierung) für den Sensor vergeben — notiere die IP.

---

## 2. Modul in IP-Symcon installieren

1. **Kern Instanzen → Module** → Bibliothek **MM-Modules** laden (Pfad zu diesem Repository).
2. **Instanz hinzufügen** → Hersteller **MM-Modules** → **Yieryi Wasserqualität**.
3. Bibliothek nach Updates neu laden und Instanz einmal **Übernehmen**.

---

## 3. Local Key ohne Tuya IoT Cloud (empfohlen)

Wenn die Verknüpfung mit [iot.tuya.com](https://iot.tuya.com) wegen **Rechenzentrum / Data Center** fehlschlägt, holst du den Key **ohne** IoT-Cloud-Projekt.

### Methode A: tuya-uncover (einfachste Variante)

Tool: [github.com/blakadder/tuya-uncover](https://github.com/blakadder/tuya-uncover)

Melde dich mit **denselben Zugangsdaten wie in der Smart-Life-App** an (nicht mit IoT-Cloud-API-Keys).

**Windows (PowerShell):**

```powershell
pip install requests
git clone https://github.com/blakadder/tuya-uncover.git
cd tuya-uncover
python uncover.py -r eu -v smartlife DEINE_EMAIL DEIN_APP_PASSWORT
```

| Parameter | Bedeutung |
|-----------|-----------|
| `-r eu` | Region Europa (bei Problemen `-r eu-w` testen) |
| `-v smartlife` | Smart Life App (bei Tuya-App: `-v tuya`) |
| Passwort mit Sonderzeichen | In Anführungszeichen: `"mein$Pass@wort"` |

**Ausgabe:** Liste aller Geräte mit u. a.:

- **`devId`** → **Tuya Device ID** in IP-Symcon
- **`localKey`** → **Tuya Local Key** in IP-Symcon

Den Eintrag mit dem Namen deines Yieryi-Sensors suchen.

### Methode B: Geräte-IP per tinytuya scan

Zusätzlich zur Device ID brauchst du die **LAN-IP**:

```powershell
pip install tinytuya
python -m tinytuya scan
```

Ausgabe enthält u. a. **IP**, **Device ID**, **Version** (Protokoll). Die Device ID mit dem Wert aus tuya-uncover abgleichen.

### Methode C: Tuya IoT Cloud (nur wenn Rechenzentrum passt)

1. [iot.tuya.com](https://iot.tuya.com) → Projekt anlegen.
2. **Rechenzentrum** muss zur App-Region passen (Deutschland meist **Central Europe**, nicht Western Europe).
3. **Devices → Link Tuya App Account** (QR-Code in der App scannen).
4. **API Explorer → Get Device Details** → `local_key` in der Antwort.

Hilfe bei Rechenzentrum-Fehlern: [Tuya-Dokumentation Data Centers](https://developer.tuya.com/en/docs/iot/oem-app-data-center-distributed?id=Kafi0ku9l07qb)

Neues Cloud-Projekt nur mit **einem** passenden Rechenzentrum anlegen; alte Projekte mit falschem Center löschen oder Rechenzentren bereinigen.

### Methode D: App-Speicher-Dump (Fallback)

Nur wenn A und C nicht funktionieren:

1. Rooted Android oder Emulator (z. B. BlueStacks mit Root).
2. Smart Life App einloggen.
3. Heap-Dump erstellen und nach `localKey` / `devId` suchen (Klasse `com.thingclips.smart.sdk.bean.DeviceBean`).

---

## 4. Werte in IP-Symcon eintragen

Instanz **Yieryi Wasserqualität** öffnen:

| Feld | Wert |
|------|------|
| **Aktiv** | ✓ |
| **Geräte-IP (LAN)** | Feste IP aus Router oder `tinytuya scan` |
| **Tuya Device ID** | `devId` aus uncover / scan |
| **Tuya Local Key** | `localKey` aus uncover |
| **Tuya-Protokollversion** | Meist **3.3** (bei Fehlern 3.4 oder 3.5 testen) |
| **Aktualisierungsintervall** | z. B. 60 Sekunden (Minimum 15) |
| **DP-Mapping** | Standard lassen (PH-W218 / 8-in-1) |

→ **Übernehmen** → Button **Jetzt aktualisieren**

### Angelegte Variablen (Auswahl)

| Variable | Bedeutung |
|----------|-----------|
| pH gemessen | pH-Wert |
| ORP gemessen | Redox (mV) |
| EC gemessen | Leitfähigkeit (µS/cm) |
| TDS gemessen | Gelöste Stoffe (ppm) |
| Wassertemperatur gemessen | Temperatur |
| Erreichbar | Sensor per LAN erreichbar |
| Letzter Fehler | Fehlertext bei Problemen |
| Roh-DPS (Debug) | Rohe Tuya-Datenpunkte |

---

## 5. Fehlerbehebung

| Symptom | Wahrscheinliche Ursache | Maßnahme |
|---------|-------------------------|----------|
| `Host, Device ID oder Local Key fehlt` | Felder leer | Alle Pflichtfelder ausfüllen, **Übernehmen** |
| `Verbindung zu …:6668 fehlgeschlagen` | Falsche IP / VLAN / Firewall | IP pingen, gleiches Netz, Port 6668 freigeben |
| `Entschlüsselung fehlgeschlagen (Local Key?)` | Falscher oder veralteter Key | uncover erneut ausführen |
| Werte bleiben leer | Falsches DP-Mapping | Variable **Roh-DPS** prüfen, Mapping anpassen |
| `objectID`-Fehler in Konsole | Symcon-UI / alter Formular-Button | Bibliothek Build 37+, Instanz **Übernehmen** |
| Sensor neu gekoppelt | Local Key geändert | Key neu holen und in Symcon eintragen |

### Standard DP-Mapping

Gilt für PH-W218 / 8-in-1 Yieryi-ähnliche Geräte:

```json
{"ph":{"dp":106,"scale":0.01},"temperature":{"dp":8,"scale":0.1},"tds":{"dp":111,"scale":1},"ec":{"dp":116,"scale":1},"orp":{"dp":131,"scale":1}}
```

Wenn **Roh-DPS** andere DP-Nummern zeigt, `scale` und `dp` entsprechend anpassen.

---

## 6. Ablauf (Übersicht)

```
Smart Life App (Sensor online)
        │
        ├─► tuya-uncover  ──► Device ID + Local Key
        │
        └─► tinytuya scan ──► LAN-IP + Protokollversion
                    │
                    ▼
        IP-Symcon: Yieryi Wasserqualität
        (IP, Device ID, Local Key, Übernehmen)
                    │
                    ▼
        Lokale Abfrage alle 15–60 s (Port 6668)
        → pH, ORP, EC, TDS, Temperatur
```

---

## 7. Optional: Pool Steuerung

Falls die Bibliothek **Pool Steuerung** (`PoolControl`) installiert ist, dort die fertig konfigurierte **Yieryi-Wasserqualität**-Instanz als Sensor-Quelle auswählen.

---

*Modul: `TuyaWaterQuality` · Präfix: `TWQT` · Bibliothek: MM-Modules*
