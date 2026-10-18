# HomeDisplay

Panel-Modul für IP-Symcon. Jede Instanz ist ein Gerät. Genau eine Instanz stellt den Adapter unter `/hook/homedisplay` bereit. Layouts und Konfigurationen liegen im gemeinsamen Katalog.

## Modul

1. Bibliothek in IP-Symcon aktualisieren.
2. Instanz `HomeDisplay` anlegen.
3. „Adapter bereitstellen“ nur bei einer Instanz aktivieren.
4. Adapter-Adresse auf die erreichbare IP des Servers setzen, wenn die automatische Erkennung nicht passt.
5. In IP-Symcon SSL einschalten, damit die Panel-Adresse mit `https` beginnt.

Schaltflächen in der Instanz:

- **Verbindung testen** zeigt letzte Meldung, Firmware, WLAN-Stärke, Speicher, Laufzeit, Reset-Grund und Datenalter des Panels.
- **Vorschau senden** zeigt dem Panel 30 Minuten lang den aktuellen Entwurf der gewählten Ansicht, auch unveröffentlicht.
- **Vollständig synchronisieren** lässt das Panel Ansicht, Konfiguration und alle Werte neu laden.
- **Testbild an/aus** blendet Farbbalken, Raster und Touch-Koordinaten für die Hardwareprüfung ein.
- **Panel neu starten** startet das Panel beim nächsten Manifest-Abruf neu.

Befehle gelangen mit dem nächsten Manifest-Abruf zum Panel (spätestens nach 16 Minuten) und werden genau einmal ausgeführt.

Aktionen vom Panel prüft `libs/PanelActionGuard.php`: nur Wertkacheln mit passender `actionId`, nur Variablen mit Aktion, Werte nur im freigegebenen Bereich, höchstens 30 Aktionen pro Minute und Gerät, doppelte `requestId` werden verworfen. Objekt- oder Skript-IDs vom Panel werden abgelehnt.

## Verwaltung

Instanz `HomeDisplay Verwaltung` anlegen, ein Passwort setzen und übernehmen. Die Adresse steht in der Instanz, zum Beispiel `http://192.168.1.10:3777/hook/homedisplay-admin/`. Der Browser fragt nach Benutzer und Passwort; der Benutzername ist beliebig.

Die Oberfläche ist ein Designer mit Menüleiste, Palette, Raster und Eigenschaften:

- Palette links: Wert, Tab-Wechsler, Text, Bild, Trennlinie und Panel-Status per Drag-and-drop ins Raster ziehen. Doppelklick legt das Element in die nächste freie Zelle.
- „Datei → Neu aus Vorlage Dashboard“ erzeugt eine komplette 1024×600-Ansicht mit Startseite, Energie, Klima, Licht, Rollladen, Pool, Garten und Systemseite. Danach Variablen zuordnen.
- Variablenbaum: nach Kategoriepfad gruppiert, sortiert, mit Suche nach Name, Pfad oder ID und Filter „Nur schaltbare“. Variable auf eine Zelle ziehen oder auf eine Wertkachel fallen lassen.
- Raster: Seitenreiter oben, Kacheln verschieben oder tauschen, Doppelklick öffnet die Details. Entf entfernt, Strg+S speichert den Entwurf.
- Bilder: Menü „Bilder …“ lädt PNG oder JPEG hoch. IP-Symcon verkleinert auf höchstens 960 × 480 Pixel; das Panel holt die Datei einmal in den Flash.
- Veröffentlichen schaltet die Ansicht für alle Panels frei, die sie in ihrer Instanz gewählt haben.

Der Editor im Ordner `editor` zeigt dieselbe Oberfläche vom PC aus und meldet den Adapter per mDNS: `python server.py http://192.168.1.10:3777 benutzer passwort`, dann `http://127.0.0.1:8765` öffnen.

## Firmware

Das Projekt liegt in `firmware`. Vor dem ersten Flash in `menuconfig` unter HomeDisplay den WLAN-Namen, das Passwort und die Adapter-URL eintragen. Die URL hat die Form `https://192.168.1.10:3777/hook/homedisplay`.

Bauen und flashen, sobald das Display per USB verbunden ist:

```text
cd firmware
idf.py set-target esp32s3
idf.py build
idf.py -p COM5 flash monitor
```

`COM5` durch den angezeigten USB-Anschluss ersetzen. Benötigt wird ESP-IDF 5.3 oder neuer. Das Abbild für den späteren Update-Abruf entsteht unter `firmware/build/homedisplay.bin`. Das Panel lädt es nur im Wartungsfenster, prüft SHA-256 und die Signatur aus `firmware/keys` und bestätigt den Start. Ohne diese Bestätigung startet die vorherige Partition.
