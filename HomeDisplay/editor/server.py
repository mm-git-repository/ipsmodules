#!/usr/bin/env python3
"""HomeDisplay-Editor für den PC.

Zeigt dieselbe Verwaltungsoberfläche wie die Instanz „HomeDisplay Verwaltung“ und spricht
ausschließlich die JSON-API von IP-Symcon. Zusätzlich meldet er den Adapter per mDNS.

Aufruf:  python server.py http://192.168.1.10:3777 benutzer passwort
"""

import base64
import json
import socket
import struct
import sys
import threading
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parent
WEB = ROOT.parent.parent / "HomeDisplayVerwaltung" / "web"
PORT = 8765
MDNS_GROUP = "224.0.0.251"
MDNS_PORT = 5353
MODULE_ID = "{4E8A1C67-9D32-4B5F-A6E1-0C7B2F94D813}"
CONNECTION = {"base": "", "user": "", "password": ""}


def ips_call(method, params):
    payload = json.dumps({"jsonrpc": "2.0", "method": method, "params": params, "id": 1}).encode("utf-8")
    request = urllib.request.Request(CONNECTION["base"].rstrip("/") + "/api/", data=payload, method="POST")
    request.add_header("Content-Type", "application/json")
    token = base64.b64encode(f"{CONNECTION['user']}:{CONNECTION['password']}".encode("utf-8")).decode("ascii")
    request.add_header("Authorization", f"Basic {token}")
    with urllib.request.urlopen(request, timeout=30) as response:
        body = json.loads(response.read().decode("utf-8"))
    if "error" in body:
        raise RuntimeError(str(body["error"]))
    result = body.get("result")
    if isinstance(result, str):
        try:
            return json.loads(result)
        except ValueError:
            return result
    return result


def panel_id():
    ids = ips_call("IPS_GetInstanceListByModuleID", [MODULE_ID])
    if not ids:
        raise RuntimeError("Keine HomeDisplay-Instanz gefunden.")
    return ids[0]


def api(incoming):
    method = incoming.get("method")
    panel = panel_id()
    if method == "catalog":
        result = ips_call("HDISP_ListCatalog", [panel])
        service = result.get("service") if isinstance(result, dict) else None
        if service and service.get("baseUrl"):
            ADVERTISER.update(service["baseUrl"])
        return result
    if method == "variables":
        return ips_call("HDISP_ListVariables", [panel])
    if method == "template":
        return ips_call("HDISP_LayoutTemplate", [panel, str(incoming.get("name", "Dashboard"))])
    if method == "images":
        return ips_call("HDISP_ListImages", [panel])
    if method == "uploadImage":
        return ips_call("HDISP_SaveImage", [panel, str(incoming.get("name", "")), str(incoming.get("data", ""))])
    if method == "deleteImage":
        return ips_call("HDISP_DeleteImage", [panel, str(incoming.get("id", ""))])
    if method in {"save", "publish"}:
        layout = json.dumps(incoming.get("layout", {}), ensure_ascii=False)
        return ips_call("HDISP_PublishLayout" if method == "publish" else "HDISP_SaveLayout", [panel, layout])
    if method in {"saveConfig", "publishConfig"}:
        config = json.dumps(incoming.get("config", {}), ensure_ascii=False)
        return ips_call("HDISP_PublishConfig" if method == "publishConfig" else "HDISP_SaveConfig", [panel, config])
    raise RuntimeError("Unbekannte Aktion.")


def encode_name(name):
    data = b""
    for part in name.rstrip(".").split("."):
        raw = part.encode("utf-8")
        data += bytes([len(raw)]) + raw
    return data + b"\x00"


def decode_name(packet, offset):
    labels = []
    jumped = False
    start = offset
    while True:
        if offset >= len(packet):
            break
        length = packet[offset]
        if length == 0:
            offset += 1
            break
        if length & 0xC0 == 0xC0:
            pointer = struct.unpack("!H", packet[offset:offset + 2])[0] & 0x3FFF
            labels.append(decode_name(packet, pointer)[0])
            offset += 2
            jumped = True
            break
        offset += 1
        labels.append(packet[offset:offset + length].decode("utf-8", "replace"))
        offset += length
    return ".".join(labels), offset if not jumped else start + 2


class Advertiser(threading.Thread):
    def __init__(self):
        super().__init__(daemon=True)
        self.url = ""
        self.host = ""
        self.lock = threading.Lock()

    def update(self, url):
        host = url.split("://", 1)[-1].split("/", 1)[0].split(":")[0]
        with self.lock:
            self.url = url
            self.host = host

    def run(self):
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            sock.bind(("", MDNS_PORT))
        except OSError:
            print("mDNS: Port 5353 ist belegt. Die Panels brauchen dann die Adapter-URL aus menuconfig.")
            return
        sock.setsockopt(socket.IPPROTO_IP, socket.IP_ADD_MEMBERSHIP, socket.inet_aton(MDNS_GROUP) + socket.inet_aton("0.0.0.0"))
        sock.settimeout(1)
        next_announce = 0
        while True:
            try:
                packet, address = sock.recvfrom(2048)
            except socket.timeout:
                packet = b""
                address = None
            with self.lock:
                url = self.url
                host = self.host
            if not host:
                continue
            now = time.time()
            queried = False
            if packet:
                try:
                    queried = self.mentions(packet)
                except Exception:
                    queried = False
            if address and queried or now >= next_announce:
                sock.sendto(self.answer(host, url), (MDNS_GROUP, MDNS_PORT))
                next_announce = now + 60

    def mentions(self, packet):
        questions = struct.unpack("!H", packet[4:6])[0]
        offset = 12
        for _ in range(questions):
            name, offset = decode_name(packet, offset)
            offset += 4
            if name.lower() in {"homedisplay.local", "_homedisplay._tcp.local"}:
                return True
        return False

    def answer(self, host, url):
        ip = socket.inet_aton(host)
        ptr = encode_name("_homedisplay._tcp.local")
        instance = encode_name("panel._homedisplay._tcp.local")
        host_name = encode_name("homedisplay.local")
        port = 3777
        hostport = url.split("://", 1)[-1].split("/", 1)[0]
        if ":" in hostport:
            port = int(hostport.rsplit(":", 1)[1])
        records = ptr + struct.pack("!HHIH", 12, 0x8001, 120, len(instance)) + instance
        srv_data = struct.pack("!HHH", 0, 0, port) + host_name
        records += instance + struct.pack("!HHIH", 33, 0x8001, 120, len(srv_data)) + srv_data
        records += host_name + struct.pack("!HHIH", 1, 0x8001, 120, 4) + ip
        txt_raw = f"url={url}".encode("utf-8")
        txt_data = bytes([len(txt_raw)]) + txt_raw
        records += instance + struct.pack("!HHIH", 16, 0x8001, 120, len(txt_data)) + txt_data
        return struct.pack("!HHHHHH", 0, 0x8400, 0, 4, 0, 0) + records


ADVERTISER = Advertiser()


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        path = self.path.split("?", 1)[0]
        if path.startswith("/images/"):
            self.serve_image(path[len("/images/"):])
            return
        name = "index.html" if path in {"/", "/index.html"} else path.lstrip("/")
        target = (WEB / name).resolve()
        if not str(target).startswith(str(WEB.resolve())) or not target.is_file():
            self.send_error(404)
            return
        kind = "text/html" if target.suffix == ".html" else "text/css" if target.suffix == ".css" else "text/javascript"
        data = target.read_bytes()
        if target.suffix == ".html":
            data = data.replace(b'<base href="/hook/homedisplay-admin/">', b'<base href="/">')
        self.respond(200, f"{kind}; charset=utf-8", data)

    def serve_image(self, name):
        image_id = name.split(".", 1)[0]
        try:
            result = ips_call("HDISP_GetImage", [panel_id(), image_id])
        except Exception:
            result = None
        if not isinstance(result, dict) or not result.get("ok"):
            self.send_error(404)
            return
        self.respond(200, result.get("mime", "image/png"), base64.b64decode(result.get("data", "")))

    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0"))
        incoming = json.loads(self.rfile.read(length).decode("utf-8") or "{}")
        try:
            if self.path.split("?", 1)[0] != "/api":
                self.send_error(404)
                return
            body = json.dumps({"ok": True, "result": api(incoming)}).encode("utf-8")
        except Exception as error:
            body = json.dumps({"ok": False, "error": str(error)}).encode("utf-8")
        self.respond(200, "application/json", body)

    def respond(self, code, kind, data):
        self.send_response(code)
        self.send_header("Content-Type", kind)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, fmt, *args):
        return


if __name__ == "__main__":
    if len(sys.argv) < 4:
        print("Aufruf: python server.py http://IP-SYMCON:3777 BENUTZER PASSWORT")
        sys.exit(1)
    CONNECTION.update(base=sys.argv[1], user=sys.argv[2], password=sys.argv[3])
    if not WEB.is_dir():
        print(f"Oberfläche fehlt: {WEB}")
        sys.exit(1)
    ADVERTISER.start()
    print(f"HomeDisplay-Editor: http://127.0.0.1:{PORT}")
    ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
