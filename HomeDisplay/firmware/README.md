# HomeDisplay Firmware

Zielgerät: Waveshare ESP32-S3-Touch-LCD-5B, 1024×600. Die Anwendung spricht nur den HomeDisplay-Gerätevertrag. Vor dem Flash WLAN und Adapter-URL in `idf.py menuconfig` unter HomeDisplay setzen.

```text
idf.py set-target esp32s3
idf.py build
idf.py -p COMx flash monitor
```

Beim ersten Start zeigt das Panel einen Kopplungscode. Diesen Code in der HomeDisplay-Instanz eintragen und „Kopplungscode übernehmen“ ausführen.
