"""Generate 192x192 schematic preview PNGs (3x 64px grid) for form.json — run from repo root or this folder."""
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
C_PV = (255, 200, 0)
BG = (0, 0, 0)

# Matches module.php renderFlowFrame
DIM = (50, 50, 50)
HOUSE_LINE = (90, 150, 255)
GREEN = (50, 220, 50)
RED = (255, 50, 50)
SPRITES = [
    (26, 0, [
        "LLLLLLLLLLLLL",
        "LBBBLBBBLBBBL",
        "LBBBLBBBLBBBL",
        "LLLLLLLLLLLLL",
        "LBBBLBBBLBBBL",
        "LBBBLBBBLBBBL",
        "LLLLLLLLLLLLL",
        "......G......",
    ], {"L": (120, 160, 220), "B": (30, 70, 200), "G": (140, 140, 140)}),
    (1, 21, [
        "....G....",
        "GGGGGGGGG",
        "G..GGG..G",
        "...G.G...",
        "..GGGGG..",
        "...G.G...",
        "..G.G.G..",
        "..GG.GG..",
        ".G.G.G.G.",
        ".GG...GG.",
        "G.......G",
    ], {"G": (170, 170, 170)}),
    (55, 20, [
        "..OOOO..",
        "OOOOOOOO",
        "O......O",
        "O......O",
        "O......O",
        "O......O",
        "O......O",
        "O......O",
        "O......O",
        "O......O",
        "O......O",
        "OOOOOOOO",
    ], {"O": (170, 170, 170)}),
    (26, 45, [
        "......R......",
        ".....RRR.....",
        "....RRRRR....",
        "...RRRRRRR...",
        "..RRRRRRRRR..",
        ".RRRRRRRRRRR.",
        "..WWWWWWWWW..",
        "..WCCWWWCCW..",
        "..WCCWWWCCW..",
        "..WWWWWDDWW..",
        "..WWWWWDDWW..",
    ], {"R": (200, 60, 40), "W": (200, 200, 200), "C": (80, 160, 230), "D": (120, 80, 40)}),
]


# Matches module.php flowGlyphs
GLYPHS = {
    "0": ["###", "#.#", "#.#", "#.#", "###"],
    "1": [".#.", "##.", ".#.", ".#.", "###"],
    "2": ["###", "..#", "###", "#..", "###"],
    "3": ["###", "..#", ".##", "..#", "###"],
    "4": ["#.#", "#.#", "###", "..#", "..#"],
    "5": ["###", "#..", "###", "..#", "###"],
    "6": ["###", "#..", "###", "#.#", "###"],
    "7": ["###", "..#", "..#", ".#.", ".#."],
    "8": ["###", "#.#", "###", "#.#", "###"],
    "9": ["###", "#.#", "###", "..#", "###"],
    "W": ["#...#", "#...#", "#.#.#", "#.#.#", ".#.#."],
    "k": ["#..", "#.#", "##.", "#.#", "#.#"],
    "%": ["##...", "##.#.", "..#..", ".#.##", "...##"],
    "€": [".###", "#...", "###.", "#...", ".###"],
    "-": ["...", "...", "###", "...", "..."],
    ",": [".", ".", ".", ".", "#"],
    ":": [".", "#", ".", "#", "."],
    " ": [".", ".", ".", ".", "."],
}


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


def draw_grid(dr: ImageDraw.ImageDraw) -> None:
    for i in range(1, 3):
        x = i * 64 * SCALE
        dr.line([(x, 0), (x, H)], fill=(40, 40, 40))
        dr.line([(0, x), (W, x)], fill=(40, 40, 40))


def to_data_uri(img: Image.Image) -> str:
    buf = io.BytesIO()
    img.save(buf, format="PNG", optimize=True)
    return "data:image/png;base64," + base64.b64encode(buf.getvalue()).decode("ascii")


def text_preview() -> str:
    consumption = 500
    generation = 1000
    net_w = 500.0
    smard_txt = "0,12€"
    battery_pct = "80%"
    battery_w = "400 W"

    img = Image.new("RGB", (W, H), BG)
    dr = ImageDraw.Draw(img)
    draw_grid(dr)

    f_label = load_font(7 * SCALE)
    f_value = load_font(8 * SCALE)

    x0 = 2 * SCALE
    rows = [
        (0, "ERZEUGUNG", f"{generation:.0f} W", C_WHITE),
        (16, "BATTERIE", battery_pct, C_WHITE),
        (32, "VERBRAUCH", f"{consumption:.0f} W", C_WHITE),
        (48, "NETZ", f"{abs(net_w):.0f} W", C_NET_IMPORT),
    ]
    for y, label, value, color in rows:
        dr.text((x0, y * SCALE), label, fill=C_LABEL, font=f_label)
        dr.text((x0, (y + 8) * SCALE), value, fill=color, font=f_value)

    def right_text(text: str, y: int, fill: tuple[int, int, int]) -> None:
        bbox = dr.textbbox((0, 0), text, font=f_value)
        width = bbox[2] - bbox[0]
        dr.text((W - width - 2 * SCALE, y * SCALE), text, fill=fill, font=f_value)

    right_text(battery_w, 24, C_CHARGE)
    right_text(smard_txt, 56, C_SMARD_POS)
    return to_data_uri(img)


def flow_preview() -> str:
    px = [[BG for _ in range(64)] for _ in range(64)]

    def put(x: int, y: int, c: tuple[int, int, int]) -> None:
        if 0 <= x < 64 and 0 <= y < 64:
            px[y][x] = c

    def hline(x0: int, x1: int, y: int, c) -> None:
        for x in range(x0, x1 + 1):
            put(x, y, c)

    def vline(x: int, y0: int, y1: int, c) -> None:
        for y in range(y0, y1 + 1):
            put(x, y, c)

    def arrow(x: int, y: int, d: str, c) -> None:
        put(x, y, c)
        if d == "d":
            put(x - 1, y - 1, c)
            put(x + 1, y - 1, c)
        elif d == "r":
            put(x - 1, y - 1, c)
            put(x - 1, y + 1, c)
        elif d == "l":
            put(x + 1, y - 1, c)
            put(x + 1, y + 1, c)

    # Example: PV 100 W, grid import 1256 W, battery idle 80 %, house 1356 W (like the reference picture)
    vline(32, 16, 26, C_PV)
    arrow(32, 22, "d", C_PV)
    hline(11, 31, 27, RED)
    arrow(21, 27, "r", RED)
    hline(33, 53, 27, DIM)
    vline(32, 28, 43, HOUSE_LINE)
    arrow(32, 33, "d", HOUSE_LINE)
    put(32, 27, (200, 200, 200))
    for x0, y0, rows, palette in SPRITES:
        for dy, row in enumerate(rows):
            for dx, ch in enumerate(row):
                if ch in palette:
                    put(x0 + dx, y0 + dy, palette[ch])
    # 80 % = 7 of 9 rows, >= 75 % green
    for r in range(7):
        hline(56, 61, 30 - r, GREEN)

    def width(t: str) -> int:
        chars = [c for c in t if c in GLYPHS]
        return sum(len(GLYPHS[c][0]) for c in chars) + max(len(chars) - 1, 0)

    def text(t: str, x: int, y: int, align: str, fill) -> None:
        w = width(t)
        if align == "r":
            x = x - w + 1
        elif align == "c":
            x = x - w // 2
        for ch in t:
            glyph = GLYPHS.get(ch)
            if glyph is None:
                continue
            for dy, row in enumerate(glyph):
                for dx, cell in enumerate(row):
                    if cell == "#":
                        put(x + dx, y + dy, fill)
            x += len(glyph[0]) + 1

    def text_unit(number: str, unit: str, x: int, y: int, align: str, fill) -> None:
        total = width(number) + 2 + width(unit)
        if align == "r":
            x = x - total + 1
        elif align == "c":
            x = x - total // 2
        text(number, x, y, "l", fill)
        text(unit, x + width(number) + 2, y, "l", fill)

    text_unit("100", "W", 32, 10, "c", C_PV)
    text_unit("1256", "W", 1, 34, "l", C_NET_IMPORT)
    text_unit("0", "W", 62, 34, "r", (255, 220, 0))
    text_unit("80", "%", 62, 41, "r", C_WHITE)
    text_unit("1356", "W", 32, 58, "c", C_WHITE)
    text("0,12€", 1, 41, "l", C_SMARD_POS)

    small = Image.new("RGB", (64, 64), BG)
    small.putdata([c for row in px for c in row])
    return to_data_uri(small.resize((W, H), Image.NEAREST))


def main() -> None:
    images = {"Vorschau": text_preview(), "Vorschau Grafikmodus": flow_preview()}

    form_path = Path(__file__).resolve().parents[1] / "form.json"
    form = json.loads(form_path.read_text(encoding="utf-8"))
    found = set()
    for element in form.get("elements", []):
        caption = element.get("caption")
        if element.get("type") == "Image" and caption in images:
            element["image"] = images[caption]
            found.add(caption)
    missing = set(images) - found
    if missing:
        raise SystemExit("Vorschau-Bild in form.json nicht gefunden: " + ", ".join(sorted(missing)))
    form_path.write_text(json.dumps(form, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
    print(json.dumps({k: len(v) for k, v in images.items()}, indent=2))


if __name__ == "__main__":
    main()
