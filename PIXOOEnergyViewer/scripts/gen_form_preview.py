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

    img = Image.new("RGB", (W, H), BG)
    dr = ImageDraw.Draw(img)
    # subtle 64px grid (logical)
    for i in range(1, 3):
        x = i * 64 * SCALE
        dr.line([(x, 0), (x, H)], fill=(40, 40, 40))
        dr.line([(0, x), (W, x)], fill=(40, 40, 40))

    f_label = load_font(7 * SCALE)
    f_value = load_font(13 * SCALE)  # ~16 logical px

    x0 = 2 * SCALE
    # y positions = module logical y * SCALE
    dr.text((x0, 2 * SCALE), "VERBRAUCH", fill=C_LABEL, font=f_label)
    dr.text((x0, 8 * SCALE), f"{consumption:.0f} W", fill=C_WHITE, font=f_value)
    dr.text((x0, 24 * SCALE), "ERZEUGUNG", fill=C_LABEL, font=f_label)
    dr.text((x0, 30 * SCALE), f"{generation:.0f} W", fill=C_WHITE, font=f_value)
    dr.text((x0, 46 * SCALE), "NETZ", fill=C_LABEL, font=f_label)

    net_str = f"{abs(net_w):.0f} W"
    dr.text((x0, 52 * SCALE), net_str, fill=C_NET_IMPORT, font=f_value)

    tw = W - 0
    bbox = dr.textbbox((0, 0), smard_txt, font=f_value)
    tw_txt = bbox[2] - bbox[0]
    dr.text((tw - tw_txt - 2, 52 * SCALE), smard_txt, fill=C_SMARD_POS, font=f_value)

    buf = io.BytesIO()
    img.save(buf, format="PNG", optimize=True)
    b64 = base64.b64encode(buf.getvalue()).decode("ascii")
    data_uri = "data:image/png;base64," + b64

    out = {"data_uri_length": len(data_uri), "prefix": data_uri[:80]}
    print(json.dumps(out, indent=2))
    out = Path(__file__).resolve().parents[1] / "_preview_b64.txt"
    out.write_text(data_uri, encoding="utf-8")
    print("Wrote", out, "(full data URI)")


if __name__ == "__main__":
    main()
