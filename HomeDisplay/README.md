# HomeDisplay

Panel-Modul für IP-Symcon. Jede Instanz ist ein Gerät. Genau eine Instanz stellt den Adapter unter `/hook/homedisplay` bereit. Layouts und Konfigurationen liegen im gemeinsamen Katalog.

## Modul

1. Bibliothek in IP-Symcon aktualisieren.
2. Instanz `HomeDisplay` anlegen.
3. „Adapter bereitstellen“ nur bei einer Instanz aktivieren.
4. Adapter-Adresse auf die erreichbare IP des Servers setzen, wenn die automatische Erkennung nicht passt.
5. In IP-Symcon SSL einschalten, damit die Panel-Adresse mit `https` beginnt.

## Verwaltung

Instanz `HomeDisplay Verwaltung` anlegen, ein Passwort setzen und übernehmen. Die Adresse steht in der Instanz, zum Beispiel `http://192.168.1.10:3777/hook/homedisplay-admin/`. Diese Seite im Browser öffnen. Der Browser fragt nach Benutzer und Passwort. Der Benutzername ist beliebig, das Passwort ist das aus der Instanz. Die Seite liest Variablen, speichert Entwürfe und veröffentlicht Ansichten. Python ist dafür nicht nötig.

## Firmware

Das Projekt liegt in `firmware`. Vor dem ersten Flash in `menuconfig` unter HomeDisplay den WLAN-Namen, das Passwort und die Adapter-URL eintragen. Die URL hat die Form `https://192.168.1.10:3777/hook/homedisplay`.

Bauen und flashen, sobald das Display per USB verbunden ist:

```text
cd firmware
idf.py set-target esp32s3
idf.py build
idf.py -p COM5 flash monitor
```

`COM5` durch den angezeigten USB-Anschluss ersetzen. Benötigt wird ESP-IDF 5.3 oder neuer. Das Abbild für den späteren Update-Abruf entsteht unter `firmware/build/homedisplay.bin`.
