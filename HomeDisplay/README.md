# HomeDisplay

Panel-Modul für IP-Symcon. Jede Instanz ist ein Gerät. Genau eine Instanz stellt den Adapter unter `/hook/homedisplay` bereit. Layouts und Konfigurationen liegen im gemeinsamen Katalog.

## Modul

1. Bibliothek in IP-Symcon aktualisieren.
2. Instanz `HomeDisplay` anlegen.
3. „Adapter bereitstellen“ nur bei einer Instanz aktivieren.
4. Adapter-Adresse auf die erreichbare IP des Servers setzen, wenn die automatische Erkennung nicht passt.
5. In IP-Symcon SSL einschalten, damit die Panel-Adresse mit `https` beginnt.

## Editor

Das Rasterprogramm liegt in `editor`. Auf dem PC `python server.py` starten und `http://127.0.0.1:8765` öffnen. Es liest Variablen über die IP-Symcon-API, setzt Kacheln in feste Rasterzellen und schreibt nur geprüfte Ansichten und Konfigurationen in den Katalog. Ein Entwurf bleibt unsichtbar, bis er veröffentlicht wird.

Die Instanz `HomeDisplay Verwaltung` liefert dieselbe Art von Seite zusätzlich über den Webserver von IP-Symcon. Adresse und Passwort stehen in der Instanz.

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
