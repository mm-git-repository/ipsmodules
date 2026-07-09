# MM-Modules

Eine IP-Symcon-Bibliothek kann **mehrere Module** enthalten. Symcon erkennt sie automatisch: Jeder Unterordner mit `module.json` wird als Modul installiert. In der `library.json` selbst gibt es **keine Modulliste** — nur Metadaten der Bibliothek (GUID, Name, Version).

## Enthaltene Module

| Ordner | Modul | Beschreibung |
|--------|-------|--------------|
| `PIXOOEnergyViewer/` | PIXOO Energy Viewer | SMA/Home-Manager-Werte auf Pixoo-Display |
| `WifiWhirl/` | WifiWhirl | Bestway-Whirlpool per HTTP (Polling) |
| `TuyaWaterQuality/` | Yieryi Wasserqualität | Yieryi/Tuya-Sensor (pH, ORP, EC, TDS) per LAN |

## Versionierung

| Datei | Feld |
|-------|------|
| `library.json` | `version`, `build` (gesamte Bibliothek) |
| `*/module.php` | `MODULE_VERSION`, `MODULE_BUILD` (pro Modul) |

Bibliothek: **1.3 (Build 37)**
