"""
SMA Energy Display -> Divoom Pixoo-64

Combines data from:
  - SMA Home Manager 2 (Speedwire multicast) -> grid buy/sell
  - SMA Sunny Tripower inverters (Modbus TCP) -> PV generation

Calculates and displays:
  - Gesamtverbrauch = Erzeugung + Netzbezug - Einspeisung
  - Erzeugung       = sum of all inverter AC power
  - Netz            = Bezug - Einspeisung

Usage (run inside the standalone/ directory):
    python sma_pixoo_display.py
    python sma_pixoo_display.py --brightness 60
"""

import argparse
import base64
import json
import signal
import sys
import time
from pathlib import Path

import requests

from sma_inverter import read_all_inverters
from sma_reader import EnergyData, create_socket, read_once

CONFIG_PATH = Path(__file__).parent / "config.json"

PIXEL_SIZE = 64
LEFT_PAD = 2

COLOR_RED = (255, 50, 50)
COLOR_GREEN = (50, 220, 50)
COLOR_YELLOW = (255, 220, 0)
COLOR_WHITE = (255, 255, 255)
COLOR_LABEL = (140, 140, 140)
COLOR_BG = (0, 0, 0)


def load_config() -> dict:
    if not CONFIG_PATH.exists():
        print(f"Fehler: {CONFIG_PATH} nicht gefunden.")
        sys.exit(1)
    with open(CONFIG_PATH, encoding="utf-8") as f:
        return json.load(f)


def pixoo_command(ip: str, payload: dict) -> dict | None:
    url = f"http://{ip}:80/post"
    try:
        resp = requests.post(url, json=payload, timeout=5)
        resp.raise_for_status()
        return resp.json()
    except requests.RequestException as e:
        print(f"  Pixoo-Fehler: {e}")
        return None


def get_pic_id(ip: str) -> int:
    result = pixoo_command(ip, {"Command": "Draw/GetHttpGifId"})
    if result and result.get("error_code", -1) == 0:
        return result.get("PicId", 1)
    return 1


def send_background(ip: str, bg_color: tuple[int, int, int]):
    pic_id = get_pic_id(ip)
    pixel = bytes(bg_color)
    frame_data = pixel * (PIXEL_SIZE * PIXEL_SIZE)
    pic_data = base64.b64encode(frame_data).decode("ascii")

    pixoo_command(ip, {
        "Command": "Draw/SendHttpGif",
        "PicNum": 1,
        "PicWidth": PIXEL_SIZE,
        "PicOffset": 0,
        "PicID": pic_id,
        "PicSpeed": 1000,
        "PicData": pic_data,
    })



def net_grid_color(net_w: float) -> tuple[int, int, int]:
    """Red = buying from grid, green = feeding in, yellow = near zero."""
    if net_w > 50:
        return COLOR_RED
    elif net_w < -50:
        return COLOR_GREEN
    return COLOR_YELLOW


FONT_LABEL = 26   # 7x7 — small uppercase labels
FONT_VALUE = 2    # 16x16 — large numbers


def make_item(
    text_id: int,
    text: str,
    x: int,
    y: int,
    color: tuple[int, int, int],
    font: int,
    height: int,
) -> dict:
    return {
        "TextId": text_id,
        "type": 22,
        "x": x,
        "y": y,
        "dir": 0,
        "font": font,
        "TextWidth": PIXEL_SIZE,
        "Textheight": height,
        "TextString": text,
        "speed": 100,
        "color": f"#{color[0]:02x}{color[1]:02x}{color[2]:02x}",
        "update_time": 0,
        "align": 1,
    }


def build_items(
    consumption_w: float,
    generation_w: float,
    net_w: float,
) -> list[dict]:
    return [
        make_item(1, "VERBRAUCH",               LEFT_PAD, 2,  COLOR_LABEL,           FONT_LABEL, 7),
        make_item(2, f"{consumption_w:.0f} W",  LEFT_PAD, 8,  COLOR_WHITE,           FONT_VALUE, 16),
        make_item(3, "ERZEUGUNG",               LEFT_PAD, 24, COLOR_LABEL,           FONT_LABEL, 7),
        make_item(4, f"{generation_w:.0f} W",   LEFT_PAD, 30, COLOR_WHITE,           FONT_VALUE, 16),
        make_item(5, "NETZ",                    LEFT_PAD, 46, COLOR_LABEL,           FONT_LABEL, 7),
        make_item(6, f"{abs(net_w):.0f} W",     LEFT_PAD, 52, net_grid_color(net_w), FONT_VALUE, 16),
    ]


def init_display(ip: str):
    """Send background once, then initial layout."""
    send_background(ip, COLOR_BG)
    items = build_items(0, 0, 0)
    pixoo_command(ip, {"Command": "Draw/SendHttpItemList", "ItemList": items})


def display_energy(
    ip: str,
    consumption_w: float,
    generation_w: float,
    net_w: float,
):
    """Re-send all items in one command (no background redraw, no flicker)."""
    items = build_items(consumption_w, generation_w, net_w)
    pixoo_command(ip, {"Command": "Draw/SendHttpItemList", "ItemList": items})


def main():
    parser = argparse.ArgumentParser(
        description="SMA Energiedaten auf Pixoo-64 anzeigen",
    )
    parser.add_argument("--pixoo-ip", help="IP des Pixoo-64 (überschreibt config.json)")
    parser.add_argument("--brightness", "-b", type=int, help="Helligkeit 0-100")
    parser.add_argument(
        "--interval", "-i", type=float,
        help="Update-Intervall in Sekunden (Standard: aus config.json)",
    )
    args = parser.parse_args()

    config = load_config()
    pixoo_ip = args.pixoo_ip or config.get("pixoo_ip", "")
    if not pixoo_ip or pixoo_ip == "DEINE_PIXOO_IP_HIER":
        print("Bitte Pixoo-IP in config.json eintragen oder mit --pixoo-ip angeben.")
        sys.exit(1)

    inverters = config.get("inverters", [])
    if not inverters:
        print("Keine Wechselrichter in config.json konfiguriert.")
        sys.exit(1)

    interval = args.interval or config.get("update_interval_seconds", 5)

    running = True

    def stop(sig, frame):
        nonlocal running
        print("\nBeende...")
        running = False

    signal.signal(signal.SIGINT, stop)
    signal.signal(signal.SIGTERM, stop)

    if args.brightness is not None:
        pixoo_command(pixoo_ip, {
            "Command": "Channel/SetBrightness",
            "Brightness": max(0, min(100, args.brightness)),
        })
        print(f"Helligkeit: {args.brightness}%")

    inv_names = ", ".join(f"{i['name']} ({i['ip']})" for i in inverters)
    print("SMA Energy Display")
    print(f"  Pixoo-64:       {pixoo_ip}")
    print(f"  HM2:            Speedwire Multicast")
    print(f"  Wechselrichter: {inv_names}")
    print(f"  Intervall:      {interval}s")
    print("  Strg+C zum Beenden.\n")

    print("Initialisiere Display...")
    init_display(pixoo_ip)

    sock = create_socket()
    last_update = 0

    while running:
        grid_data = read_once(sock)
        if grid_data is None:
            print("Keine Daten vom SMA HM2 (Timeout). Warte...")
            continue

        now = time.time()
        if now - last_update < interval:
            continue

        last_update = now

        # Grid: buy/sell from Home Manager 2
        net_w = grid_data.power_buy_w - grid_data.power_sell_w

        # PV generation: sum of all inverters via Modbus TCP
        generation_w, inv_results = read_all_inverters(inverters)

        # Total consumption = generation + net grid (buy - sell)
        consumption_w = generation_w + net_w

        # Console log
        inv_detail = "  ".join(
            f"{r.name}:{r.power_w:.0f}W" if r.reachable else f"{r.name}:ERR"
            for r in inv_results
        )
        print(
            f"[{time.strftime('%H:%M:%S')}] "
            f"Verbr: {consumption_w:.0f} W | "
            f"Erzeug: {generation_w:.0f} W | "
            f"Netz: {net_w:+.0f} W  "
            f"({inv_detail})"
        )

        try:
            display_energy(pixoo_ip, consumption_w, generation_w, net_w)
        except Exception as e:
            print(f"  Pixoo Display-Fehler: {e}")

    sock.close()
    print("Beendet.")


if __name__ == "__main__":
    main()
