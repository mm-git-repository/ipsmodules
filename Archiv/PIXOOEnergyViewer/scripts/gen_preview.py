"""Generate display_preview.png (64x64) for Symcon form Image data-URI."""
from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

W = H = 64
SCALE = 3  # export 192x192 for sharper preview in Symcon
LW, LH = W * SCALE, H * SCALE

# Colors (hex from module)
LABEL = (140, 140, 140)
WHITE = (255, 255, 255)
RED = (255, 50, 50)
GREEN = (50, 220, 50)
SMARD_G = (50, 220, 50)


def main() -> None:
    img = Image.new("RGB", (LW, LH), (0, 0, 0))
    d = ImageDraw.Draw(img)
    try:
        f_small = ImageFont.truetype("arial.ttf", 7 * SCALE)
        f_num = ImageFont.truetype("arial.ttf", 11 * SCALE)
    except OSError:
        f_small = ImageFont.load_default()
        f_num = ImageFont.load_default()

    def tx(x: int, y: int, text: str, fill: tuple[int, int, int], font) -> None:
        d.text((x * SCALE, y * SCALE), text, fill=fill, font=font)

    tx(2, 2, "VERBRAUCH", LABEL, f_small)
    tx(2, 8, "1234 W", WHITE, f_num)
    tx(2, 24, "ERZEUGUNG", LABEL, f_small)
    tx(2, 30, "5678 W", WHITE, f_num)
    tx(2, 46, "NETZ", LABEL, f_small)
    tx(2, 52, "890 W", RED, f_num)
    # corner: nur SMARD €/kWh (wie Modul bei aktivem SMARD-Preis)
    d.text((28 * SCALE, 56 * SCALE), "0,12€/kWh", fill=SMARD_G, font=f_small)

    out = Path(__file__).resolve().parent.parent / "imgs" / "display_preview.png"
    out.parent.mkdir(parents=True, exist_ok=True)
    img.save(out, "PNG")
    print(out, img.size)


if __name__ == "__main__":
    main()
