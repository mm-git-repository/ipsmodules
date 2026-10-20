# HomeDisplay

Modul für die Hausanzeige in IP-Symcon. Jede Instanz `Hausanzeige` ist ein Gerät. Alle globalen Einstellungen liegen in der Instanz `Hausanzeige Verwaltung`: Sie richtet den Dienst unter `/hook/homedisplay` ein, über den alle Anzeigen Ansichten, Werte und Programm abholen, und hält die Dienstadresse. Ansichten und Konfigurationen liegen im gemeinsamen Katalog.

## Modul

1. Bibliothek in IP-Symcon aktualisieren.
2. Instanz `Hausanzeige Verwaltung` anlegen, Passwort setzen und übernehmen. Sie richtet den Dienst automatisch ein; er läuft über die erste aktive Anzeige-Instanz und wandert bei deren Löschung weiter.
3. Dienstadresse in der Verwaltung auf die erreichbare IP des Servers setzen, wenn die automatische Erkennung nicht passt. Die Adresse gilt für Dienst und Verwaltungsseite.
4. In IP-Symcon SSL einschalten. Die Anzeige spricht HTTPS und prüft das eigene Zertifikat von IP-Symcon nicht. Das ist die Voreinstellung „Lokales HTTPS-Zertifikat nicht prüfen“ im Programm.
5. Je Anzeige eine Instanz `Hausanzeige` anlegen und koppeln. Die Instanz zeigt die Dienstadresse an und meldet Status 203, solange keine aktive Verwaltung vorhanden ist.

Schaltflächen in der Instanz:

- **Verbindung testen** zeigt letzte Meldung, Programm, WLAN-Stärke, Speicher, Laufzeit, Reset-Grund und Datenalter der Anzeige.
- **Vorschau senden** zeigt der Anzeige 30 Minuten lang den aktuellen Entwurf der gewählten Ansicht, auch unveröffentlicht.
- **Vollständig synchronisieren** lässt die Anzeige Ansicht, Konfiguration und alle Werte neu laden.
- **Testbild an/aus** blendet Farbbalken, Raster und Berührungskoordinaten für die Hardwareprüfung ein.
- **Anzeige neu starten** startet die Anzeige beim nächsten Abruf neu.

Befehle gelangen beim nächsten Abruf zur Anzeige (spätestens nach 16 Minuten) und werden genau einmal ausgeführt.

Aktionen von der Anzeige prüft `libs/PanelActionGuard.php`: nur Wertkacheln mit passender `actionId`, nur Variablen mit Aktion, Werte nur im freigegebenen Bereich, höchstens 30 Aktionen pro Minute und Gerät, doppelte `requestId` werden verworfen. Objekt- oder Skriptkennungen von der Anzeige werden abgelehnt.

## Verwaltung

Instanz `Hausanzeige Verwaltung` anlegen, ein Passwort setzen und übernehmen. Die Adresse steht in der Instanz, zum Beispiel `http://192.168.1.10:3777/hook/homedisplay-admin/`. Der Browser fragt nach Benutzer und Passwort; der Benutzername ist beliebig.

Die Oberfläche ist ein Designer mit Menüleiste, Palette und Raster. Eigenschaften einer Kachel öffnet der Doppelklick:

- Palette links: Wert, Seitenwechsler, Text, Bild, Trennlinie und Anzeigestatus per Drag-and-drop ins Raster ziehen. Doppelklick legt das Element in die nächste freie Zelle.
- „Datei → Neu aus Vorlage Übersicht“ erzeugt eine komplette 1024×600-Ansicht mit Startseite, Energie, Klima, Licht, Rollladen, Pool, Garten und Systemseite. Danach Variablen zuordnen.
- Variablenbaum: nach Kategoriepfad gruppiert, sortiert, mit Suche nach Name, Pfad oder ID und Filter „Nur schaltbare“. Variable auf eine Zelle ziehen oder auf eine Wertkachel fallen lassen.
- Raster: Seitenreiter oben, Kacheln verschieben oder tauschen, Doppelklick öffnet die Details. Entf entfernt, Strg+S speichert den Entwurf.
- Bilder: Menü „Bilder …“ lädt PNG oder JPEG hoch. IP-Symcon verkleinert auf höchstens 1024 × 600 Bildpunkte; die Anzeige holt die Datei einmal in den Speicher. Über „Hintergrund dieser Seite“ liegt ein Bild hinter dem Raster einer Seite.
- Veröffentlichen schaltet die Ansicht für alle Anzeigen frei, die sie in ihrer Instanz gewählt haben.
- Menü „Einstellungen …“ ändert nur die einmalige Dienstadresse und zeigt die Anzeigen. Kopplung, Ansicht, Helligkeit, Ruheanzeige und Abruf stehen in der jeweiligen Instanz „Hausanzeige“.
- Menü „Prüfen …“ zeigt Ansicht und Werte wie die Anzeige sie abruft und kann eine freigegebene Aktion ausführen.

Der Editor im Ordner `editor` zeigt dieselbe Oberfläche vom PC aus und meldet den Dienst per mDNS: `python server.py http://192.168.1.10:3777 benutzer passwort`, dann `http://127.0.0.1:8765` öffnen.

## Programm der Anzeige

Es gibt zwei Firmware-Pfade:

| Hardware | Ordner | Binary für OTA |
|----------|--------|----------------|
| Waveshare ESP32-S3 5B (1024×600, Touch) | `firmware/` (ESP-IDF) | `firmware/build/homedisplay.bin` |
| FireBeetle 2 + E-Ink 4,2"/7,5" | `firmware-firebeetle/` (PlatformIO) | `firmware-firebeetle/build/firmware.bin` |

In der Instanz **Hausanzeige** das passende **Display-Profil** wählen; OTA und Manifest liefern dann die richtige Datei. Details E-Ink: [`firmware-firebeetle/README.md`](firmware-firebeetle/README.md).

### Waveshare (ESP-IDF)

Das Projekt liegt in `firmware`. Vor dem ersten Schreiben in `menuconfig` unter HomeDisplay den WLAN-Namen, das Passwort und die Dienstadresse eintragen. Die Adresse hat die Form `https://192.168.1.10:3777/hook/homedisplay`.

Bauen und auf die Anzeige schreiben, sobald sie per USB verbunden ist:

```text
cd firmware
idf.py set-target esp32s3
idf.py build
idf.py -p COM5 flash monitor
```

`COM5` durch den angezeigten USB-Anschluss ersetzen. Benötigt wird ESP-IDF 5.3 oder neuer. Die Datei für die spätere Aktualisierung entsteht unter `firmware/build/homedisplay.bin`. Die Anzeige lädt sie nur im Wartungsfenster, prüft SHA-256 und die Signatur aus `firmware/keys` und bestätigt den Start. Ohne diese Bestätigung startet die vorherige Partition.
