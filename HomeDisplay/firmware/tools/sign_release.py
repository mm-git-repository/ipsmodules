"""Copy the built firmware into release/ and write firmware.json with hash and signature.

The signature is RSA PKCS#1 v1.5 over SHA-256 of the whole image, the same scheme ota.c verifies.
"""

import argparse
import base64
import hashlib
import json
import shutil
import sys
from datetime import datetime, timezone
from pathlib import Path

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding

FIRMWARE = Path(__file__).resolve().parent.parent
NEW_KEY_SINCE = "0.6.78"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--key", required=True, help="private OTA key (PEM)")
    parser.add_argument("--image", default=str(FIRMWARE / "build" / "homedisplay.bin"))
    args = parser.parse_args()

    image = Path(args.image)
    key_path = Path(args.key)
    if not image.is_file():
        print(f"Programmdatei fehlt: {image}", file=sys.stderr)
        return 1
    if not key_path.is_file():
        print(f"Schluessel fehlt: {key_path}", file=sys.stderr)
        return 1

    version = (FIRMWARE / "VERSION").read_text(encoding="utf-8").strip()
    data = image.read_bytes()
    key = serialization.load_pem_private_key(key_path.read_bytes(), password=None)
    signature = key.sign(data, padding.PKCS1v15(), hashes.SHA256())
    key.public_key().verify(signature, data, padding.PKCS1v15(), hashes.SHA256())

    # Anzeigen vor 0.6.78 kennen nur den alten Schluessel. Ein mit dem aktuellen Schluessel signiertes
    # Release verlangt deshalb mindestens 0.6.78, damit sie vorher das Uebergangs-Release bekommen.
    current_public = serialization.load_pem_public_key((FIRMWARE / "keys" / "ota-public.pem").read_bytes())
    fingerprint = lambda public: public.public_bytes(serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo)
    requires = NEW_KEY_SINCE if fingerprint(key.public_key()) == fingerprint(current_public) else ""

    release = FIRMWARE / "release"
    release.mkdir(exist_ok=True)
    shutil.copyfile(image, release / "homedisplay.bin")
    descriptor = {
        "version": version,
        "requires": requires,
        "sha256": hashlib.sha256(data).hexdigest(),
        "signature": base64.b64encode(signature).decode("ascii"),
        "size": len(data),
        "built": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }
    (release / "firmware.json").write_text(json.dumps(descriptor, indent=2) + "\n", encoding="utf-8")
    print(f"Release {version}: {len(data)} Byte, SHA-256 {descriptor['sha256']}, requires '{requires}'")
    return 0


if __name__ == "__main__":
    sys.exit(main())
