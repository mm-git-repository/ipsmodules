"""Generate 192x192 schematic preview PNG (3x 64px grid) for form.json — run from repo root or this folder."""
from __future__ import annotations

import base64
import io
import json
import sys
from pathlib import Path

try:
    from PIL import Image, ImageDraw, ImageFont
except ImportError:
    print("pip install pillow", file=sys.stderr)
    raise

SCALE = 3
W = H = 64 * SCALE
# Matches module.php BuildItems / NetGridColorHex / smardPriceColorHex
C_LABEL = (140, 140, 140)
C_WHITE = (255, 255, 255)
C_NET_IMPORT = (255, 50, 50)
C_CHARGE = (50, 220, 50)
C_SMARD_POS = (50, 220, 50)
BG = (0, 0, 0)


def load_font(size: int):
    candidates = [
        Path(r"C:\Windows\Fonts\segoeui.ttf"),
        Path(r"C:\Windows\Fonts\arial.ttf"),
        Path("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"),
    ]
    for p in candidates:
        if p.is_file():
            try:
                return ImageFont.truetype(str(p), size=size)
            except OSError:
                continue
    return ImageFont.load_default()


def main() -> None:
    consumption = 500
    generation = 1000
    net_w = 500.0
    smard_txt = "0,12€"
    battery_pct = "80%"
    battery_w = "400 W"

    img = Image.new("RGB", (W, H), BG)
    dr = ImageDraw.Draw(img)
    # subtle 64px grid (logical)
    for i in range(1, 3):
        x = i * 64 * SCALE
        dr.line([(x, 0), (x, H)], fill=(40, 40, 40))
        dr.line([(0, x), (W, x)], fill=(40, 40, 40))

    f_label = load_font(6 * SCALE)
    f_value = load_font(8 * SCALE)

    x0 = 2 * SCALE
    rows = [
        (0, "VERBRAUCH", f"{consumption:.0f} W", C_WHITE),
        (16, "ERZEUGUNG", f"{generation:.0f} W", C_WHITE),
        (32, "NETZ", f"{abs(net_w):.0f} W", C_NET_IMPORT),
        (48, "BATTERIE", battery_pct, C_WHITE),
    ]
    for y, label, value, color in rows:
        dr.text((x0, y * SCALE), label, fill=C_LABEL, font=f_label)
        dr.text((x0, (y + 6) * SCALE), value, fill=color, font=f_value)

    def right_text(text: str, y: int, fill: tuple[int, int, int]) -> None:
        bbox = dr.textbbox((0, 0), text, font=f_value)
        width = bbox[2] - bbox[0]
        dr.text((W - width - 2 * SCALE, y * SCALE), text, fill=fill, font=f_value)

    right_text(smard_txt, 38, C_SMARD_POS)
    right_text(battery_w, 54, C_CHARGE)

    buf = io.BytesIO()
    img.save(buf, format="PNG", optimize=True)
    b64 = base64.b64encode(buf.getvalue()).decode("ascii")
    data_uri = "data:image/png;base64," + b64

    form_path = Path(__file__).resolve().parents[1] / "form.json"
    form = json.loads(form_path.read_text(encoding="utf-8"))
    replaced = False
    for element in form.get("elements", []):
        if element.get("type") == "Image" and element.get("caption") == "Vorschau":
            element["image"] = data_uri
            replaced = True
            break
    if not replaced:
        raise SystemExit("Vorschau-Bild in form.json nicht gefunden")
    form_path.write_text(json.dumps(form, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
    print(json.dumps({"data_uri_length": len(data_uri), "form": str(form_path)}, indent=2))


if __name__ == "__main__":
    main()
