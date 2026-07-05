"""Patch form.json Image element with data URI from _preview_b64.txt (repo root)."""
from __future__ import annotations

import json
import sys
from pathlib import Path

MOD = Path(__file__).resolve().parents[1]
FORM = MOD / "form.json"
B64 = MOD / "_preview_b64.txt"


def main() -> int:
    if not B64.is_file():
        print("Missing", B64, file=sys.stderr)
        return 1
    data_uri = B64.read_text(encoding="utf-8").strip()
    form = json.loads(FORM.read_text(encoding="utf-8"))
    for el in form.get("elements", []):
        if el.get("type") == "Image" and el.get("caption") == "Vorschau":
            el["image"] = data_uri
            break
    else:
        print("Image Vorschau not found", file=sys.stderr)
        return 1
    FORM.write_text(json.dumps(form, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
    print("Updated", FORM)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
