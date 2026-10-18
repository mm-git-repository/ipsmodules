#!/usr/bin/env python3
"""Lokaler HomeDisplay-Editor. Spricht nur die IP-Symcon-JSON-API und meldet den Adapter per mDNS."""

import json
import socket
import struct
import threading
import time
import urllib.request
import base64
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PORT = 8765
MDNS_GROUP = "224.0.0.251"
MDNS_PORT = 5353


def ips_call(base, user, password, method, params):
    payload = json.dumps({"jsonrpc": "2.0", "method": method, "params": params, "id": 1}).encode("utf-8")
    request = urllib.request.Request(base.rstrip("/") + "/api/", data=payload, method="POST")
    request.add_header("Content-Type", "application/json")
    token = base64.b64encode(f"{user}:{password}".encode("utf-8")).decode("ascii")
    request.add_header("Authorization", f"Basic {token}")
    with urllib.request.urlopen(request, timeout=15) as response:
        body = json.loads(response.read().decode("utf-8"))
    if "error" in body:
        raise RuntimeError(str(body["error"]))
    return body.get("result")


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
        ptr_data = instance
        port = 3777
        hostport = url.split("://", 1)[-1].split("/", 1)[0]
        if ":" in hostport:
            port = int(hostport.rsplit(":", 1)[1])
        records = ptr + struct.pack("!HHIH", 12, 0x8001, 120, len(ptr_data)) + ptr_data
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
        name = "index.html" if self.path in {"/", "/index.html"} else self.path.lstrip("/")
        path = (ROOT / name).resolve()
        if not str(path).startswith(str(ROOT)) or not path.is_file():
            self.send_error(404)
            return
        kind = "text/html" if path.suffix == ".html" else "text/css" if path.suffix == ".css" else "text/javascript"
        data = path.read_bytes()
        self.send_response(200)
        self.send_header("Content-Type", f"{kind}; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0"))
        incoming = json.loads(self.rfile.read(length).decode("utf-8") or "{}")
        try:
            if self.path == "/api/call":
                result = ips_call(incoming["base"], incoming["user"], incoming["password"], incoming["method"], incoming.get("params", []))
                if incoming["method"] == "HDISP_GetService" and isinstance(result, str):
                    info = json.loads(result)
                    if info.get("baseUrl"):
                        ADVERTISER.update(info["baseUrl"])
                body = json.dumps({"ok": True, "result": result}).encode("utf-8")
            else:
                self.send_error(404)
                return
        except Exception as error:
            body = json.dumps({"ok": False, "error": str(error)}).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt, *args):
        return


if __name__ == "__main__":
    ADVERTISER.start()
    print(f"HomeDisplay-Editor: http://127.0.0.1:{PORT}")
    ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
