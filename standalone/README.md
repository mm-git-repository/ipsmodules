# Standalone: Divoom Pixoo-64 + SMA (Python)

Zeigt Echtzeit-Energiedaten vom SMA Home Manager 2 auf einem Divoom Pixoo-64 an. Zusätzlich CLI zum Senden von beliebigem Text.

Alle Befehle unten im Ordner **`standalone/`** ausführen (oder Pfade anpassen).

## Setup

1. Abhängigkeiten:

   ```bash
   cd standalone
   pip install -r requirements.txt
   ```

2. **`config.json`** in diesem Ordner anpassen:

   ```json
   {
       "pixoo_ip": "192.168.1.100",
       "sma_ip": "172.18.1.157",
       "default_brightness": 80,
       "update_interval_seconds": 5
   }
   ```

   - **pixoo_ip**: IP des Pixoo-64 (Divoom-App)
   - **sma_ip**: IP des SMA Home Manager 2 (für Speedwire-Multicast nicht zwingend dieselbe Zeile nötig)

## Energie-Display (SMA → Pixoo)

```bash
cd standalone

# Starten (liest config.json)
python sma_pixoo_display.py

# Mit expliziter Pixoo-IP
python sma_pixoo_display.py --pixoo-ip 192.168.1.100

# Helligkeit setzen
python sma_pixoo_display.py --brightness 60

# Update-Intervall (Sekunden)
python sma_pixoo_display.py --interval 3
```

### Anzeige

- **BEZUG** (rot): Netzbezug in Watt  
- **EINSPEIS.** (grün): Einspeisung in Watt  
- **~0 W** (grau): nahezu ausgeglichen  

Der SMA Home Manager 2 sendet per UDP-Multicast (Speedwire) an `239.12.255.254:9522`. Der Rechner muss im gleichen LAN sein und Multicast empfangen können.

## Text-Tool

```bash
cd standalone

python pixoo_text.py "Hallo Welt!"
python pixoo_text.py "Achtung!" --color rot
python pixoo_text.py "Hi" --static --x 10 --y 20
python pixoo_text.py --clear
python pixoo_text.py --brightness 50
```

### Farbnamen

`rot`, `grün`, `blau`, `weiß`, `gelb`, `cyan`, `magenta`, `orange`, `pink`, `lila`, `schwarz`

## Windows-Dienst (NSSM)

`install_service.bat` und `uninstall_service.bat` liegen in **`standalone/`** und müssen dort **als Administrator** ausgeführt werden. Sie setzen `AppDirectory` auf diesen Ordner und starten `sma_pixoo_display.py`.

## Modbus-Diagnose

```bash
cd standalone
python debug_modbus.py
```
