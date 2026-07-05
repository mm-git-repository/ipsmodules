"""
Divoom Pixoo-64 Text Sender CLI

Send text, scrolling messages, and more to your Pixoo-64 device.
Uses the Divoom local HTTP API directly for maximum compatibility.
"""

import argparse
import base64
import json
import sys
from pathlib import Path

import requests

CONFIG_PATH = Path(__file__).parent / "config.json"

PIXEL_COUNT = 64
FRAME_BYTES = PIXEL_COUNT * PIXEL_COUNT * 3  # 64x64 pixels, 3 bytes (RGB) each


def load_config() -> dict:
    if not CONFIG_PATH.exists():
        print(f"Fehler: config.json nicht gefunden in {CONFIG_PATH}")
        sys.exit(1)

    with open(CONFIG_PATH, encoding="utf-8") as f:
        config = json.load(f)

    ip = config.get("pixoo_ip") or config.get("device_ip", "")
    if not ip or ip in ("DEINE_IP_HIER", "DEINE_PIXOO_IP_HIER"):
        print("Bitte trage die IP deines Pixoo-64 in config.json ein.")
        print('Beispiel: "pixoo_ip": "192.168.1.100"')
        sys.exit(1)
    config["pixoo_ip"] = ip

    return config


def send_command(ip: str, payload: dict) -> dict | None:
    url = f"http://{ip}:80/post"
    try:
        resp = requests.post(url, json=payload, timeout=5)
        resp.raise_for_status()
        return resp.json()
    except requests.ConnectionError:
        print(f"Fehler: Kann Pixoo nicht erreichen unter {ip}")
        print("Prüfe ob das Gerät eingeschaltet und im selben WLAN ist.")
        sys.exit(1)
    except requests.Timeout:
        print(f"Fehler: Timeout bei Verbindung zu {ip}")
        sys.exit(1)


def get_next_pic_id(ip: str) -> int:
    result = send_command(ip, {"Command": "Draw/GetHttpGifId"})
    if result and result.get("error_code", -1) == 0:
        return result.get("PicId", 1)
    return 1


def send_background_frame(
    ip: str,
    bg_color: tuple[int, int, int] = (0, 0, 0),
):
    """Send a solid-color background frame that text can be overlaid on."""
    pic_id = get_next_pic_id(ip)
    pixel = bytes(bg_color)
    frame_data = pixel * (PIXEL_COUNT * PIXEL_COUNT)
    pic_data = base64.b64encode(frame_data).decode("ascii")

    payload = {
        "Command": "Draw/SendHttpGif",
        "PicNum": 1,
        "PicWidth": PIXEL_COUNT,
        "PicOffset": 0,
        "PicID": pic_id,
        "PicSpeed": 1000,
        "PicData": pic_data,
    }
    result = send_command(ip, payload)
    if not result or result.get("error_code", -1) != 0:
        print(f"Warnung: Hintergrund konnte nicht gesendet werden: {result}")
        return False
    return True


def send_text(
    ip: str,
    text: str,
    x: int = 0,
    y: int = 0,
    color: tuple[int, int, int] = (255, 255, 255),
    bg_color: tuple[int, int, int] = (0, 0, 0),
    speed: int = 0,
    scroll: bool = True,
    font: int = 0,
    align: int = 1,
    text_id: int = 1,
):
    """Send text with automatic background frame."""
    send_background_frame(ip, bg_color)

    payload = {
        "Command": "Draw/SendHttpText",
        "TextId": text_id,
        "x": x,
        "y": y,
        "dir": 0 if scroll else 1,
        "font": font,
        "TextWidth": PIXEL_COUNT,
        "speed": speed if scroll else 0,
        "TextString": text,
        "color": f"#{color[0]:02x}{color[1]:02x}{color[2]:02x}",
        "align": align,
    }
    result = send_command(ip, payload)
    if result and result.get("error_code", -1) == 0:
        mode = "scrollend" if scroll else "statisch"
        print(f"Text ({mode}) gesendet: \"{text}\"")
    else:
        print(f"Fehler beim Senden: {result}")


def clear_text(ip: str):
    """Clear all text from the display."""
    result = send_command(ip, {"Command": "Draw/ClearHttpText"})
    if result and result.get("error_code", -1) == 0:
        print("Text gelöscht.")
    else:
        print(f"Fehler beim Löschen: {result}")


def set_brightness(ip: str, brightness: int):
    payload = {
        "Command": "Channel/SetBrightness",
        "Brightness": max(0, min(100, brightness)),
    }
    result = send_command(ip, payload)
    if result and result.get("error_code", -1) == 0:
        print(f"Helligkeit auf {brightness}% gesetzt.")
    else:
        print(f"Fehler: {result}")


def parse_color(color_str: str) -> tuple[int, int, int]:
    color_str = color_str.strip().lstrip("#")

    presets = {
        "rot": (255, 0, 0),
        "red": (255, 0, 0),
        "grün": (0, 255, 0),
        "green": (0, 255, 0),
        "blau": (0, 0, 255),
        "blue": (0, 0, 255),
        "weiß": (255, 255, 255),
        "weiss": (255, 255, 255),
        "white": (255, 255, 255),
        "gelb": (255, 255, 0),
        "yellow": (255, 255, 0),
        "cyan": (0, 255, 255),
        "magenta": (255, 0, 255),
        "orange": (255, 165, 0),
        "pink": (255, 105, 180),
        "lila": (128, 0, 128),
        "purple": (128, 0, 128),
        "schwarz": (0, 0, 0),
        "black": (0, 0, 0),
    }

    if color_str.lower() in presets:
        return presets[color_str.lower()]

    if len(color_str) == 6:
        try:
            r = int(color_str[0:2], 16)
            g = int(color_str[2:4], 16)
            b = int(color_str[4:6], 16)
            return (r, g, b)
        except ValueError:
            pass

    if "," in color_str:
        parts = [int(p.strip()) for p in color_str.split(",")]
        if len(parts) == 3:
            return (parts[0], parts[1], parts[2])

    print(f"Ungültige Farbe: {color_str}")
    print("Verwende: Farbname (rot, grün, blau, ...), Hex (#ff0000), oder RGB (255,0,0)")
    sys.exit(1)


def main():
    parser = argparse.ArgumentParser(
        description="Sende Text an dein Divoom Pixoo-64",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""Beispiele:
  python pixoo_text.py "Hallo Welt!"
  python pixoo_text.py "Wichtig!" --color rot
  python pixoo_text.py "Test" --color "#00ff00" --speed 50
  python pixoo_text.py "Fix" --static --x 10 --y 20
  python pixoo_text.py "Hi" --bg blau --color gelb
  python pixoo_text.py --clear
  python pixoo_text.py --brightness 50
""",
    )
    parser.add_argument("text", nargs="?", help="Der Text der angezeigt werden soll")
    parser.add_argument(
        "--ip", help="IP-Adresse des Pixoo (überschreibt config.json)"
    )
    parser.add_argument(
        "--color", "-c", default="weiß",
        help="Textfarbe: Name, Hex oder R,G,B (Standard: weiß)",
    )
    parser.add_argument(
        "--bg", default="schwarz",
        help="Hintergrundfarbe: Name, Hex oder R,G,B (Standard: schwarz)",
    )
    parser.add_argument(
        "--speed", "-s", type=int, default=100,
        help="Scroll-Geschwindigkeit in ms (Standard: 100)",
    )
    parser.add_argument(
        "--font", "-f", type=int, default=0,
        help="Font-ID 0-7 (Standard: 0)",
    )
    parser.add_argument(
        "--static", action="store_true",
        help="Statischer Text (nicht scrollend)",
    )
    parser.add_argument("--x", type=int, default=0, help="X-Position (Standard: 0)")
    parser.add_argument("--y", type=int, default=20, help="Y-Position (Standard: 20, Mitte)")
    parser.add_argument(
        "--align", "-a", type=int, default=1, choices=[1, 2, 3],
        help="Ausrichtung: 1=links, 2=mitte, 3=rechts (Standard: 1)",
    )
    parser.add_argument(
        "--clear", action="store_true", help="Text vom Display löschen",
    )
    parser.add_argument(
        "--brightness", "-b", type=int, help="Helligkeit setzen (0-100)",
    )

    args = parser.parse_args()
    config = load_config()
    ip = args.ip or config["pixoo_ip"]

    if args.brightness is not None:
        set_brightness(ip, args.brightness)
        if not args.text and not args.clear:
            return

    if args.clear:
        clear_text(ip)
        return

    if not args.text:
        parser.print_help()
        sys.exit(1)

    color = parse_color(args.color)
    bg_color = parse_color(args.bg)

    send_text(
        ip,
        args.text,
        x=args.x,
        y=args.y,
        color=color,
        bg_color=bg_color,
        speed=args.speed,
        scroll=not args.static,
        font=args.font,
        align=args.align,
    )


if __name__ == "__main__":
    main()
