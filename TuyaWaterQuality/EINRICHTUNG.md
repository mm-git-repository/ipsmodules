# Yieryi Wasserqualität — Einrichtungsanleitung

Anleitung für das IP-Symcon-Modul **Yieryi Wasserqualität** (`TuyaWaterQuality`) der Bibliothek **MM-Modules**.

Das Modul liest **pH, ORP, EC, TDS und Wassertemperatur** per **lokalem Tuya-LAN-Protokoll** (Port 6668). Der laufende Betrieb ist **100 % lokal** — für die Einrichtung kann einmalig die **Tuya-Cloud-Kopplung im Formular** (QR-Login wie Home Assistant) genutzt werden.

---

## Wichtig vorab

| Thema | Erklärung |
|-------|-----------|
| **Primärer Weg (ab Build 5)** | **Tuya-Kopplung** im Instanz-Formular: User Code → QR scannen → Gerät übernehmen → Local Key + Device ID automatisch |
| **Local Key in der App** | In **Tuya Smart** / **Smart Life** wird der Key **nicht angezeigt**. Das ist normal — Kopplung oder Cloud-Login holen ihn. |
| **Nur lokal im Betrieb** | Nach der Kopplung: IP-Symcon und Sensor im **gleichen LAN**, Abfrage Port **6668**, **kein** Cloud-Polling |
| **Internet** | Nur **einmalig** beim QR-Login (HTTPS zu `apigw.iotbing.com` / Tuya-Endpoint) und für die QR-Bildanzeige |
| **Linkify-Meldung** | Warnung *„The plugin Linkify cannot be loaded“* kommt vom **Browser/Adblocker**, nicht vom Modul |

---

## 1. Sensor vorbereiten

1. Sensor in **Tuya Smart** oder **Smart Life** einrichten.
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
| 2 | **QR-Code anzeigen** → Browser mit QR-Bild öffnet sich (IP-Symcon unterstützt kein HTML im Dialog) |
| 3 | **Auf Anmeldung warten** (bis zu ~1 Minute Wartezeit pro Klick) |
| 4 | **Gerät aus Cloud-Liste** wählen (z. B. YINMIK Water Quality Tester) |
| 5 | **Gerät übernehmen** → Felder Device ID, Local Key, Host, DP-Mapping werden gesetzt |
| 6 | **Übernehmen** → **Jetzt aktualisieren** |

Optional:

- **LAN-Scan (IP)** — UDP-Discovery oder Hinweis auf `python -m tinytuya scan`
- **Cloud-Session beenden** — temporäre Token löschen (Local Key bleibt in den Feldern)

### YINMIK / szjcy DP-Mapping (automatisch)

Bei Product ID `u5xgcpcngk3pfxb4` oder Kategorie `szjcy`:

```json
{"tds":{"dp":1,"scale":0.001},"temperature":{"dp":2,"scale":0.1}}
```

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

### Methode C: tuya-uncover (veraltet / oft defekt)

[github.com/blakadder/tuya-uncover](https://github.com/blakadder/tuya-uncover) — bei vielen Accounts inzwischen **nicht mehr zuverlässig** (`SING_VALIDATE_FALED_4` bei Tuya Smart).

### Methode D: Tuya IoT Cloud

Nur wenn Rechenzentrum zur App-Region passt: [iot.tuya.com](https://iot.tuya.com) → App verknüpfen → Device Details → `local_key`.

---

## 5. Manuelle Werte in IP-Symcon

| Feld | Wert |
|------|------|
| **Aktiv** | ✓ |
| **Geräte-IP (LAN)** | Feste IP / Scan / Cloud |
| **Tuya Device ID** | z. B. `bf304e1f1e35c32232syye` |
| **Tuya Local Key** | aus Kopplung oder HA-Script |
| **Tuya-Protokollversion** | Meist **3.3** |
| **Aktualisierungsintervall** | z. B. 60 s (Minimum 15) |
| **DP-Mapping** | Standard (8-in-1) oder YINMIK (siehe oben) |

→ **Übernehmen** → **Jetzt aktualisieren**

### Variablen (Auswahl)

| Variable | Bedeutung |
|----------|-----------|
| pH / ORP / EC / TDS / Wassertemperatur | Messwerte |
| Erreichbar | LAN-Verbindung OK |
| Roh-DPS (Debug) | Rohe Tuya-Datenpunkte |

---

## 6. Fehlerbehebung

| Symptom | Maßnahme |
|---------|----------|
| QR-Login schlägt fehl | User Code prüfen, Internet/Firewall, erneut scannen |
| `OpenSSL fehlt` | PHP-OpenSSL auf Symcon-Server aktivieren (für Geräteliste) |
| `Entschlüsselung fehlgeschlagen` | Local Key veraltet → erneut kopplern |
| Werte leer | **Roh-DPS** prüfen, DP-Mapping anpassen |
| Keine IP nach Kopplung | **LAN-Scan** oder Router-Reservierung |
| Sensor neu gekoppelt | **Gerät übernehmen** erneut ausführen |

### Standard DP-Mapping (PH-W218 / 8-in-1)

```json
{"ph":{"dp":106,"scale":0.01},"temperature":{"dp":8,"scale":0.1},"tds":{"dp":111,"scale":1},"ec":{"dp":116,"scale":1},"orp":{"dp":131,"scale":1}}
```

---

## 7. Ablauf (Übersicht)

```
Tuya Smart (Sensor online)
        │
        ├─► Formular QR-Kopplung ──► Device ID + Local Key + Mapping
        │
        └─► LAN-Scan / Router-IP ──► Host
                    │
                    ▼
        IP-Symcon: Yieryi Wasserqualität (Übernehmen)
                    │
                    ▼
        Lokale Abfrage alle 15–60 s (Port 6668)
```

---

## 8. Optional: Pool Steuerung

Mit **Pool Steuerung** (`PoolControl`) die konfigurierte **Yieryi-Wasserqualität**-Instanz als Sensor-Quelle wählen.

---

*Modul: `TuyaWaterQuality` · Präfix: `TWQT` · Build 5 · Bibliothek: MM-Modules*
