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
    ".": [".", ".", ".", ".", "#"],
    "°": [".#.", "#.#", ".#.", "...", "..."],
    ":": [".", "#", ".", "#", "."],
    " ": [".", ".", ".", ".", "."],
    "A": [".#.", "#.#", "###", "#.#", "#.#"],
    "B": ["##.", "#.#", "##.", "#.#", "##."],
    "C": [".##", "#..", "#..", "#..", ".##"],
    "D": ["##.", "#.#", "#.#", "#.#", "##."],
    "E": ["###", "#..", "##.", "#..", "###"],
    "F": ["###", "#..", "##.", "#..", "#.."],
    "G": [".##", "#..", "#.#", "#.#", ".##"],
    "H": ["#.#", "#.#", "###", "#.#", "#.#"],
    "I": ["###", ".#.", ".#.", ".#.", "###"],
    "J": ["..#", "..#", "..#", "#.#", ".#."],
    "K": ["#.#", "##.", "#..", "##.", "#.#"],
    "L": ["#..", "#..", "#..", "#..", "###"],
    "M": ["#.#", "###", "#.#", "#.#", "#.#"],
    "N": ["##.", "#.#", "#.#", "#.#", "#.#"],
    "O": [".#.", "#.#", "#.#", "#.#", ".#."],
    "P": ["##.", "#.#", "##.", "#..", "#.."],
    "Q": [".#.", "#.#", "#.#", ".##", "..#"],
    "R": ["##.", "#.#", "##.", "#.#", "#.#"],
    "S": [".##", "#..", ".#.", "..#", "##."],
    "T": ["###", ".#.", ".#.", ".#.", ".#."],
    "U": ["#.#", "#.#", "#.#", "#.#", ".##"],
    "V": ["#.#", "#.#", "#.#", "#.#", ".#."],
    "X": ["#.#", "#.#", ".#.", "#.#", "#.#"],
    "Y": ["#.#", "#.#", ".#.", ".#.", ".#."],
    "Z": ["###", "..#", ".#.", "#..", "###"],
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
    px = [[BG for _ in range(64)] for _ in range(64)]

    def put(x: int, y: int, c: tuple[int, int, int]) -> None:
        if 0 <= x < 64 and 0 <= y < 64:
            px[y][x] = c

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

    rows = [
        (0, "ERZEUGUNG", "1000", C_WHITE),
        (32, "VERBRAUCH", "500", C_WHITE),
        (48, "NETZ", "500", C_NET_IMPORT),
    ]
    for y, label, number, color in rows:
        text(label, 2, y, "l", C_LABEL)
        text_unit(number, "W", 2, y + 6, "l", color)
    text("BATTERIE", 2, 16, "l", C_LABEL)
    text_unit("80", "%", 2, 22, "l", C_WHITE)
    text_unit("400", "W", 62, 22, "r", C_CHARGE)
    text("14:30", 62, 48, "r", C_LABEL)
    text_unit("0,12", "€", 62, 54, "r", C_SMARD_POS)

    small = Image.new("RGB", (64, 64), BG)
    small.putdata([c for row in px for c in row])
    return to_data_uri(small.resize((W, H), Image.NEAREST))


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
    # 80 % von 54 Innenpixeln, ab y=30 aufwärts, 6 Pixel je Zeile: rot, gelb, grün
    yellow = (255, 220, 0)
    for i in range(43):
        y = 30 - (i // 6)
        x = 56 + (i % 6)
        color = RED if i < 18 else yellow if i < 36 else GREEN
        put(x, y, color)
    sun = [
        "....Y....",
        ".Y..Y..Y.",
        "..YYYYY..",
        "YYYYYYYYY",
        "..YYYYY..",
        ".Y..Y..Y.",
        "....Y....",
    ]
    for dy, row in enumerate(sun):
        for dx, ch in enumerate(row):
            if ch == "Y":
                put(19 + dx, 1 + dy, yellow)

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
    text("21°", 17, 1, "r", C_LABEL)
    text("DO 01.01.25", 62, 1, "r", C_LABEL)
    text("14:30", 62, 7, "r", C_LABEL)
    text_unit("0,12", "€", 1, 41, "l", C_SMARD_POS)

    small = Image.new("RGB", (64, 64), BG)
    small.putdata([c for row in px for c in row])
    return to_data_uri(small.resize((W, H), Image.NEAREST))


def main() -> None:
    images = {"Vorschau": text_preview(), "Vorschau Grafikmodus": flow_preview()}

    form_path = Path(__file__).resolve().parents[1] / "form.json"
    form = json.loads(form_path.read_text(encoding="utf-8"))
    found = set()

    def walk(node) -> None:
        if isinstance(node, list):
            for item in node:
                walk(item)
            return
        if not isinstance(node, dict):
            return
        caption = node.get("caption")
        if node.get("type") == "Image" and caption in images:
            node["image"] = images[caption]
            found.add(caption)
        for value in node.values():
            walk(value)

    walk(form.get("elements", []))
    missing = set(images) - found
    if missing:
        raise SystemExit("Vorschau-Bild in form.json nicht gefunden: " + ", ".join(sorted(missing)))
    form_path.write_text(json.dumps(form, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
    print(json.dumps({k: len(v) for k, v in images.items()}, indent=2))


if __name__ == "__main__":
    main()
