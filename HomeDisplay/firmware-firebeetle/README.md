# HomeDisplay FireBeetle 2 + E-Ink

PlatformIO-Firmware für DFRobot FireBeetle 2 ESP32-E mit Waveshare DESPI-C02 (GxEPD2, 4,2" 400×300 oder 7,5" 800×480, S/W oder S/W/R). Sie nutzt denselben Dienst `/hook/homedisplay` wie die Waveshare-ESP-IDF-Firmware — kein PapyrOS-Backend.

## Hardware

- Board: `dfrobot_firebeetle2_esp32e`
- E-Paper SPI: SCK 18, MISO 19, MOSI 23, CS 5, DC 17, RST 16, BUSY 4 (wie papyrOSClientESP32)
- Taste GPIO39: nächste Layout-Seite
- Onboard-LED GPIO2 (optional)

## Ersteinrichtung

**USB-Serial (115200)** oder **Setup-WLAN**:

1. `pio run -t upload` (USB, 115200)
2. **Serial-Menü:** In den ersten **8 s** nach Boot `m` + Enter, oder jederzeit in der Laufzeit `m` + Enter im Monitor.
   - `0` = Assistent (Dienstadresse, WLAN, Display)
   - `s` = Speichern & Neustart
   - `k` = Kopplung löschen
   - `f` = Factory Reset (bestätigen mit `RESET`)
3. Alternativ SoftAP **Hausanzeige-Setup-XXXX**, Passwort `hausanzeige` → Browser-Formular
4. In IP-Symcon Instanz **Hausanzeige**: Display-Profil `FireBeetle E-Ink 4,2"` oder `7,5"` wählen, Kopplungscode vom E-Paper eintragen

**HTTP/HTTPS:** Muss zum IPS-Webserver passen (`WebServer::SSL` aus → `http://IP:3777/hook/homedisplay`, SSL an → `https://…`). Nur IP ohne Schema wird als `http://` gespeichert. TLS-Zertifikat wird bei HTTPS nicht geprüft.

## Build

```text
cd firmware-firebeetle
pio run
```

Artefakt: `build/firmware.bin` — auf den Symcon-Host nach `HomeDisplay/firmware-firebeetle/build/` kopieren für OTA (Manifest liefert SHA-256 und Signatur aus `firmware/keys`).

Version steht in `VERSION` und `platformio.ini` (`HOMEDISPLAY_FB_FIRMWARE_VERSION`).

## Betrieb

- Dauerbetrieb: WLAN bleibt aktiv, Abrufintervall aus IPS (`StateIntervalSec`, mindestens 5 s in der Firmware)
- E-Ink-Update bei Layout- oder State-Änderung (Vollrefresh)
- Kacheln v1: Wert, Text, Nav, Trennlinie, System, Bild/Kamera/Medien (JPEG, Graustufen)

## Abgrenzung

- Kein Touch, kein LVGL, keine Helligkeitssteuerung
- Waveshare-Firmware bleibt unter `../firmware/` (ESP-IDF)
