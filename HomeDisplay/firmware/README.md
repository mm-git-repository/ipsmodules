# HomeDisplay Firmware

Zielgerät: Waveshare ESP32-S3-Touch-LCD-5B, 1024×600 RGB, GT911-Touch, CH422G-Portexpander (Backlight an EXIO2), RTC PCF85063 am gemeinsamen I2C-Bus. Die Anwendung spricht nur den HomeDisplay-Gerätevertrag.

```text
idf.py set-target esp32s3
idf.py build
idf.py -p COMx flash monitor
```

## Einrichtung

1. Ohne WLAN-Daten in `menuconfig` und ohne gespeicherte Zugangsdaten öffnet das Panel zehn Minuten lang das WLAN `HomeDisplay-Setup-XXXX`. Dort `http://192.168.4.1` öffnen, WLAN und optional die Adapter-URL eintragen. Das Panel speichert die Daten im NVS und startet neu.
2. Alternativ WLAN und Adapter-URL fest in `idf.py menuconfig` unter HomeDisplay eintragen.
3. Beim ersten Kontakt zeigt das Panel einen Kopplungscode. Diesen Code in der HomeDisplay-Instanz eintragen und „Kopplungscode übernehmen“ ausführen.

## Verhalten im Betrieb

- Ansicht, Einstellungen und letzte Werte liegen als Kopie in der Partition `storage`. Nach einem Neustart erscheint sofort die zwischengespeicherte Ansicht.
- Ist der Adapter nicht erreichbar, zeigt die Kopfzeile „veraltet seit …“, die Kacheln werden abgedunkelt und Aktionen melden „Keine Verbindung“, statt gesendet zu werden.
- WLAN-Abbrüche werden mit steigendem Abstand (2 s bis 60 s) neu verbunden; nach fünf Minuten ohne Verbindung startet der WLAN-Treiber neu.
- Der Task-Watchdog (30 s) überwacht den Client-Task. Jede Gesundheitsmeldung enthält RSSI, freien Heap, Laufzeit, Reset-Grund und Datenalter.
- Befehle aus der IP-Symcon-Instanz (Neustart, Testbild, vollständige Synchronisierung) kommen mit dem nächsten Manifest an und werden genau einmal ausgeführt.
- Die RTC wird einmal pro Stunde mit der Adapterzeit nachgestellt und liefert nach einem Neustart die Uhrzeit, bevor WLAN steht.

## Prüfliste für die Hardware-Inbetriebnahme

Die Firmware ist für die Variante **5B** ausgelegt (1024×600). Die Variante **5** hat 800×480 und braucht andere Timings in `board.c`. Vor dem ersten Flash den Aufdruck auf der Platine prüfen.

1. **Testbild**: In `menuconfig` „Testbild nach dem Start“ auf 120 setzen, bauen, flashen. Erwartet: acht Farbbalken oben, Graukeil, 16 senkrechte Rasterlinien im Abstand 64 px, unten Zähler für Bilder und Berührungen sowie Touch-Koordinaten. Kein Flackern, kein Versatz, Linien durchgehend scharf bis zum rechten Rand (x = 1023).
2. **Touch**: Alle vier Ecken und die Mitte antippen; Koordinaten müssen zu Ecke bzw. Mitte passen (0/0, 1023/0, 0/599, 1023/599, 512/300 ± 10 px).
3. **Backlight**: Konfiguration mit `idleMode` 2 veröffentlichen. Nach 60 s ohne Berührung muss das Backlight ausgehen und bei Berührung wieder an.
4. **RTC**: Panel mit Adapter verbinden, dann WLAN-Router kurz trennen und Panel neu starten. Die Kopfzeile muss die richtige Uhrzeit aus der RTC zeigen, bevor WLAN steht. Fehlt die Pufferbatterie, meldet das Log `RTC nicht gefunden` oder die Zeit ist ungültig.
5. **24-Stunden-Lauf**: „Testbild nach dem Start“ auf 86400 setzen, Panel 24 h laufen lassen. Erwartet: Bildzähler zählt lückenlos weiter (ca. 86400), kein Neustart (Laufzeit im Testbild zählt durch, `resetReason` bleibt 1 = Power-on), kein Bildversatz. Danach den Wert auf 0 zurücksetzen.
6. **Datenbetrieb**: Layout mit allen fünf Kachelarten veröffentlichen, Werte über 30 Minuten beobachten, Adapter stoppen und prüfen, dass „veraltet seit“ erscheint und Aktionen gesperrt sind. Adapter starten: Werte müssen innerhalb von zwei Abrufintervallen wieder aktuell sein.
7. **Ausfalltest OTA**: Ein absichtlich kaputtes Abbild (zum Beispiel mit vertauschter Signatur) bereitstellen. Erwartet: Update wird abgelehnt, `updateError` erscheint im Panel-Status in IP-Symcon. Danach ein gültiges Abbild ohne Netzwerk nach dem Neustart: Nach 180 s ohne Bestätigung muss die alte Partition starten und `rollback` gemeldet werden.
