#!/usr/bin/env python3
"""Live WSS probe for Gardena Gen2 schedules. Credentials via env/argv only."""

from __future__ import annotations

import argparse
import base64
import json
import os
import random
import socket
import ssl
import struct
import sys
import time
import uuid
from typing import Any


def log(topic: str, msg: str) -> None:
    print(f"[{topic}] {msg}", flush=True)


class GardenaWss:
    def __init__(self, host: str, password: str, port: int = 8443, timeout: float = 12.0):
        self.host = host
        self.password = password
        self.port = port
        self.timeout = timeout

    def connect(self) -> ssl.SSLSocket:
        last_err: Exception | None = None
        for attempt in range(1, 7):
            try:
                raw = socket.create_connection((self.host, self.port), timeout=self.timeout)
                ctx = ssl.create_default_context()
                ctx.check_hostname = False
                ctx.verify_mode = ssl.CERT_NONE
                sock = ctx.wrap_socket(raw, server_hostname=self.host)
                sock.settimeout(self.timeout)
                key = base64.b64encode(os.urandom(16)).decode()
                auth = base64.b64encode(f"_:{self.password}".encode()).decode()
                req = (
                    f"GET / HTTP/1.1\r\n"
                    f"Host: {self.host}:{self.port}\r\n"
                    f"Upgrade: websocket\r\n"
                    f"Connection: Upgrade\r\n"
                    f"Sec-WebSocket-Key: {key}\r\n"
                    f"Sec-WebSocket-Version: 13\r\n"
                    f"Authorization: Basic {auth}\r\n"
                    f"\r\n"
                )
                sock.sendall(req.encode())
                header = b""
                while b"\r\n\r\n" not in header:
                    chunk = sock.recv(1)
                    if not chunk:
                        raise RuntimeError("handshake EOF")
                    header += chunk
                    if len(header) > 8192:
                        raise RuntimeError("handshake too large")
                status = header.split(b"\r\n", 1)[0].decode(errors="replace")
                if " 101 " not in status and not status.endswith(" 101"):
                    raise RuntimeError(f"handshake failed: {status}")
                log("WSS", f"connected attempt={attempt}")
                return sock
            except Exception as e:  # noqa: BLE001
                last_err = e
                log("WSS", f"connect attempt {attempt}/6 failed: {e}")
                time.sleep(0.35 * attempt if attempt < 6 else 0)
        raise RuntimeError(f"connect failed: {last_err}")

    @staticmethod
    def close(sock: ssl.SSLSocket | None) -> None:
        if sock is None:
            return
        try:
            sock.close()
        except Exception:  # noqa: BLE001
            pass

    def send_json(self, sock: ssl.SSLSocket, data: Any) -> None:
        payload = json.dumps(data, ensure_ascii=False, separators=(",", ":")).encode()
        self._send_frame(sock, payload)

    def _send_frame(self, sock: ssl.SSLSocket, payload: bytes) -> None:
        header = bytearray([0x81])
        n = len(payload)
        mask_bit = 0x80
        if n <= 125:
            header.append(mask_bit | n)
        elif n <= 65535:
            header.append(mask_bit | 126)
            header.extend(struct.pack("!H", n))
        else:
            header.append(mask_bit | 127)
            header.extend(struct.pack("!Q", n))
        mask = os.urandom(4)
        header.extend(mask)
        masked = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
        sock.sendall(header + masked)

    def recv_frame(self, sock: ssl.SSLSocket, timeout: float) -> bytes | None:
        sock.settimeout(max(0.05, timeout))
        try:
            h = self._read_exact(sock, 2)
            if h is None:
                return None
            b1, b2 = h[0], h[1]
            opcode = b1 & 0x0F
            masked = (b2 & 0x80) != 0
            length = b2 & 0x7F
            if length == 126:
                ext = self._read_exact(sock, 2)
                if ext is None:
                    return None
                length = struct.unpack("!H", ext)[0]
            elif length == 127:
                ext = self._read_exact(sock, 8)
                if ext is None:
                    return None
                length = struct.unpack("!Q", ext)[0]
            mask = b""
            if masked:
                mask = self._read_exact(sock, 4) or b""
                if len(mask) != 4:
                    return None
            data = self._read_exact(sock, length)
            if data is None:
                return None
            if masked:
                data = bytes(b ^ mask[i % 4] for i, b in enumerate(data))
            if opcode == 0x8:  # close
                return None
            if opcode == 0x9:  # ping -> pong
                # ignore for probe
                return b""
            return data
        except (socket.timeout, TimeoutError):
            return None
        except OSError:
            return None

    @staticmethod
    def _read_exact(sock: ssl.SSLSocket, n: int) -> bytes | None:
        buf = b""
        while len(buf) < n:
            try:
                chunk = sock.recv(n - len(buf))
            except (socket.timeout, TimeoutError):
                return None
            if not chunk:
                return None
            buf += chunk
        return buf

    def build_request(
        self,
        op: str,
        service: str,
        path: str,
        payload: dict[str, Any] | None = None,
        device_id: str | None = None,
    ) -> dict[str, Any]:
        entity: dict[str, Any] = {"path": path, "service": service}
        if device_id:
            entity["device"] = device_id
        req: dict[str, Any] = {
            "entity": entity,
            "op": op,
            "request_id": str(uuid.uuid4()),
        }
        if payload is not None:
            req["payload"] = payload
        return req

    def exchange(self, requests: list[dict[str, Any]], timeout: float | None = None) -> list[dict[str, Any]]:
        sock = self.connect()
        try:
            return self._exchange_on(sock, requests, timeout or self.timeout)
        finally:
            self.close(sock)

    def _exchange_on(
        self, sock: ssl.SSLSocket, requests: list[dict[str, Any]], timeout: float
    ) -> list[dict[str, Any]]:
        self.send_json(sock, requests)
        want = {str(r["request_id"]): True for r in requests if "request_id" in r}
        got: list[dict[str, Any]] = []
        deadline = time.time() + timeout
        buffer = ""
        while want and time.time() < deadline:
            raw = self.recv_frame(sock, max(0.2, deadline - time.time()))
            if raw is None:
                continue
            if raw == b"":
                continue
            buffer += raw.decode("utf-8", errors="replace")
            chunks, buffer = extract_json_values(buffer)
            for decoded in chunks:
                for msg in flatten_messages(decoded):
                    rid = str(msg.get("request_id", ""))
                    if rid and rid in want:
                        del want[rid]
                        got.append(msg)
                    elif not want:
                        got.append(msg)
        if want:
            raise RuntimeError(f"timeout replies {len(got)}/{len(requests)} missing={list(want)}")
        return got

    def discover(self) -> dict[str, Any]:
        devices: dict[str, Any] = {}
        frames = 0
        # Sequential reads are more reliable than one batched frame on busy websocketd
        for service in ("lemonbeatd", "lwm2mserver"):
            sock = self.connect()
            try:
                req = self.build_request("read", service, "devices")
                self.send_json(sock, [req])
                deadline = time.time() + max(8.0, self.timeout * 0.8)
                buffer = ""
                last_hit = time.time()
                got_payload = False
                while time.time() < deadline:
                    raw = self.recv_frame(sock, max(0.2, min(2.0, deadline - time.time())))
                    if raw is None:
                        if got_payload and (time.time() - last_hit) > 1.5:
                            break
                        continue
                    if raw == b"":
                        continue
                    frames += 1
                    text = raw.decode("utf-8", errors="replace")
                    buffer += text
                    chunks, buffer = extract_json_values(buffer)
                    if not chunks and frames <= 3:
                        log("WSS", f"discover {service} raw={text[:220]}")
                    for decoded in chunks:
                        for msg in flatten_messages(decoded):
                            payload = extract_discover_payload(msg)
                            if not payload:
                                continue
                            got_payload = True
                            last_hit = time.time()
                            for did, data in payload.items():
                                if not isinstance(data, dict) or len(did) < 8:
                                    continue
                                if did not in devices:
                                    devices[did] = data
                                else:
                                    devices[did] = deep_merge(devices[did], data)
            finally:
                self.close(sock)
            time.sleep(0.3)
        log("WSS", f"discover devices={len(devices)} frames={frames}")
        return devices


def extract_json_values(buffer: str) -> tuple[list[Any], str]:
    out: list[Any] = []
    i = 0
    n = len(buffer)
    while i < n:
        while i < n and buffer[i].isspace():
            i += 1
        if i >= n:
            break
        if buffer[i] not in "{[":
            # skip garbage
            i += 1
            continue
        start = i
        depth = 0
        in_str = False
        esc = False
        for j in range(i, n):
            c = buffer[j]
            if in_str:
                if esc:
                    esc = False
                elif c == "\\":
                    esc = True
                elif c == '"':
                    in_str = False
                continue
            if c == '"':
                in_str = True
            elif c in "{[":
                depth += 1
            elif c in "}]":
                depth -= 1
                if depth == 0:
                    chunk = buffer[start : j + 1]
                    try:
                        out.append(json.loads(chunk))
                    except json.JSONDecodeError:
                        pass
                    i = j + 1
                    break
        else:
            # incomplete
            return out, buffer[start:]
    return out, ""


def flatten_messages(decoded: Any) -> list[dict[str, Any]]:
    if isinstance(decoded, list):
        msgs: list[dict[str, Any]] = []
        for item in decoded:
            msgs.extend(flatten_messages(item))
        return msgs
    if isinstance(decoded, dict):
        for key in ("data", "messages", "payload"):
            if key in decoded and isinstance(decoded[key], (list, dict)) and key != "payload":
                inner = flatten_messages(decoded[key])
                if inner:
                    return inner
        return [decoded]
    return []


def is_device_map(m: dict[str, Any]) -> bool:
    if not m:
        return False
    if isinstance(m, list):
        return False
    if "success" in m or "request_id" in m or "error" in m:
        return False
    device_like = 0
    for key, value in m.items():
        if not isinstance(key, str) or not isinstance(value, dict):
            return False
        if len(key) < 8:
            return False
        if any(k in value for k in ("actuator", "device", "schedule", "lemonbeat", "connection_status", "sg_common")):
            device_like += 1
    return device_like > 0


def extract_discover_payload(msg: dict[str, Any]) -> dict[str, Any] | None:
    if msg.get("success") is True and isinstance(msg.get("payload"), dict) and is_device_map(msg["payload"]):
        return msg["payload"]
    if isinstance(msg.get("payload"), dict) and is_device_map(msg["payload"]):
        return msg["payload"]
    if is_device_map(msg):
        return msg
    return None


def deep_merge(a: dict[str, Any], b: dict[str, Any]) -> dict[str, Any]:
    out = dict(a)
    for k, v in b.items():
        if k in out and isinstance(out[k], dict) and isinstance(v, dict):
            out[k] = deep_merge(out[k], v)
        else:
            out[k] = v
    return out


def field_value(field: Any) -> Any:
    if isinstance(field, dict):
        if "vi" in field:
            return field["vi"]
        if "vs" in field:
            return field["vs"]
        if "vf" in field:
            return field["vf"]
        if "vo" in field:
            return field["vo"]
        if "value" in field:
            return field["value"]
    return field


def parse_slots(device: dict[str, Any]) -> list[dict[str, Any]]:
    schedules = device.get("schedule")
    if not isinstance(schedules, dict):
        return []
    rules = []
    for slot_id, sch in schedules.items():
        if slot_id == "_urn" or not isinstance(sch, dict):
            continue
        start = int(field_value(sch.get("start_offset_seconds")) or 0)
        end = int(field_value(sch.get("end_offset_seconds")) or 0)
        rep = int(field_value(sch.get("repetition_value")) or 0)
        rep_type = field_value(sch.get("repetition_type"))
        actuator = int(field_value(sch.get("actuator")) or 0)
        if start == 0 and end == 0 and rep == 0:
            continue
        rules.append(
            {
                "slot": str(slot_id),
                "actuator": actuator,
                "start_offset_seconds": start,
                "end_offset_seconds": end,
                "repetition_value": rep,
                "repetition_type": rep_type,
                "start_hm": sec_to_hm(start),
                "end_hm": sec_to_hm(end),
                "raw_keys": sorted(sch.keys()),
            }
        )
    rules.sort(key=lambda r: int(r["slot"]))
    return rules


def sec_to_hm(sec: int) -> str:
    sec = max(0, sec % 86400)
    return f"{sec // 3600:02d}:{(sec % 3600) // 60:02d}"


def find_valve_device(devices: dict[str, Any]) -> tuple[str, dict[str, Any]]:
    best: tuple[str, dict[str, Any], int] | None = None
    for did, data in devices.items():
        if not isinstance(data, dict):
            continue
        slots = parse_slots(data)
        has_act = "actuator" in data or any(k.startswith("actuator") for k in data)
        score = len(slots) * 10 + (5 if has_act else 0) + (3 if "schedule" in data else 0)
        name = str(field_value(data.get("name")) or data.get("product") or "")
        if "2814" in name or "Water" in name or "Dual" in name:
            score += 20
        if best is None or score > best[2]:
            best = (did, data, score)
    if best is None:
        raise RuntimeError("no devices found")
    return best[0], best[1]


def write_req(client: GardenaWss, device_id: str, slot: str, field: str, value: int) -> dict[str, Any]:
    return client.build_request(
        "write",
        "lwm2mserver",
        f"schedule/{slot}/{field}",
        {"vi": int(value)},
        device_id,
    )


def rules_fingerprint(rules: list[dict[str, Any]]) -> str:
    parts = []
    for r in sorted(rules, key=lambda x: int(x["slot"])):
        parts.append(
            f"{r['slot']}|{r['actuator']}|{r['start_offset_seconds']}|{r['end_offset_seconds']}|{r['repetition_value']}"
        )
    return ";".join(parts)


def mutate_rules(rules: list[dict[str, Any]], delta_min: int = 15) -> list[dict[str, Any]]:
    out = []
    for r in rules:
        nr = dict(r)
        # shift start/end by +15 min, wrap within day keeping duration
        dur = nr["end_offset_seconds"] - nr["start_offset_seconds"]
        start = (nr["start_offset_seconds"] + delta_min * 60) % 86400
        end = start + dur
        if end >= 86400:
            start = max(0, 86400 - dur - 60)
            end = start + dur
        nr["start_offset_seconds"] = start
        nr["end_offset_seconds"] = end
        nr["start_hm"] = sec_to_hm(start)
        nr["end_hm"] = sec_to_hm(end)
        out.append(nr)
    return out


def build_active_writes(
    client: GardenaWss,
    device_id: str,
    rules: list[dict[str, Any]],
    include_rep_type: bool = False,
    field_order: list[str] | None = None,
) -> list[dict[str, Any]]:
    order = field_order or ["actuator", "repetition_value", "start_offset_seconds", "end_offset_seconds"]
    reqs = []
    for r in rules:
        values = {
            "actuator": int(r["actuator"]),
            "repetition_value": int(r["repetition_value"]),
            "start_offset_seconds": int(r["start_offset_seconds"]),
            "end_offset_seconds": int(r["end_offset_seconds"]),
        }
        if include_rep_type:
            values["repetition_type"] = 1
            if "repetition_type" not in order:
                order = ["actuator", "repetition_type", "repetition_value", "start_offset_seconds", "end_offset_seconds"]
        for field in order:
            if field in values:
                reqs.append(write_req(client, device_id, str(r["slot"]), field, values[field]))
    return reqs


def build_clear_writes(
    client: GardenaWss, device_id: str, used_slots: set[str], previous_max: int
) -> list[dict[str, Any]]:
    clear_until = max(max((int(s) for s in used_slots), default=-1), previous_max, 3)
    clear_until = min(clear_until, 35)
    reqs = []
    for i in range(0, clear_until + 1):
        slot = str(i)
        if slot in used_slots:
            continue
        for field in ("start_offset_seconds", "end_offset_seconds", "repetition_value"):
            reqs.append(write_req(client, device_id, slot, field, 0))
    return reqs


def path_of(req: dict[str, Any]) -> str:
    return str(req.get("entity", {}).get("path", ""))


def msg_mentions_path(msg: dict[str, Any], path: str) -> bool:
    blob = json.dumps(msg, ensure_ascii=False)
    return path in blob


def is_write_failure(msg: dict[str, Any]) -> bool:
    if msg.get("success") is False:
        return True
    if msg.get("ok") is False:
        return True
    return False


def strategy_a_loose(
    client: GardenaWss, device_id: str, rules: list[dict[str, Any]], ack_wait: float = 4.0, attempts: int = 3
) -> float:
    """Current IPS style: one field per send, any frame = ACK."""
    reqs = build_active_writes(client, device_id, rules)
    return _write_sequential(client, reqs, ack_wait=ack_wait, attempts=attempts, path_specific=False)


def strategy_b_batch_slot(client: GardenaWss, device_id: str, rules: list[dict[str, Any]]) -> float:
    """One JSON array per slot (4 writes), wait for any frames briefly."""
    t0 = time.time()
    sock = client.connect()
    try:
        for r in rules:
            reqs = build_active_writes(client, device_id, [r])
            log("WSS", f"batch-slot {r['slot']} writes={len(reqs)}")
            client.send_json(sock, reqs)
            deadline = time.time() + 3.0
            got = 0
            while time.time() < deadline:
                raw = client.recv_frame(sock, max(0.15, deadline - time.time()))
                if raw is None:
                    if got > 0 and time.time() > deadline - 1.5:
                        break
                    continue
                if raw == b"":
                    continue
                got += 1
                preview = raw[:180]
                log("WSS", f"batch frame={preview!r}")
                for decoded in extract_json_values(raw.decode("utf-8", errors="replace"))[0]:
                    for msg in flatten_messages(decoded):
                        if is_write_failure(msg):
                            raise RuntimeError(f"write rejected: {msg}")
            time.sleep(0.15)
    finally:
        client.close(sock)
        time.sleep(0.5)
    return time.time() - t0


def strategy_c_path_ack(
    client: GardenaWss, device_id: str, rules: list[dict[str, Any]], ack_wait: float = 1.5
) -> float:
    reqs = build_active_writes(client, device_id, rules)
    return _write_sequential(client, reqs, ack_wait=ack_wait, attempts=3, path_specific=True)


def strategy_d_alt_order(client: GardenaWss, device_id: str, rules: list[dict[str, Any]]) -> float:
    order = ["start_offset_seconds", "end_offset_seconds", "repetition_value", "actuator"]
    reqs = build_active_writes(client, device_id, rules, include_rep_type=True, field_order=order)
    # repetition_type optional ACK
    return _write_sequential(
        client,
        reqs,
        ack_wait=1.5,
        attempts=2,
        path_specific=True,
        optional_substrings=["/repetition_type"],
    )


def strategy_e_clear_acked(
    client: GardenaWss, device_id: str, rules: list[dict[str, Any]], previous_max: int
) -> float:
    t0 = time.time()
    used = {str(r["slot"]) for r in rules}
    active = build_active_writes(client, device_id, rules)
    clears = build_clear_writes(client, device_id, used, previous_max)
    _write_sequential(client, active, ack_wait=1.5, attempts=3, path_specific=True)
    if clears:
        log("WSS", f"clear writes={len(clears)}")
        _write_sequential(client, clears, ack_wait=1.2, attempts=2, path_specific=True)
    return time.time() - t0


def _write_sequential(
    client: GardenaWss,
    reqs: list[dict[str, Any]],
    ack_wait: float,
    attempts: int,
    path_specific: bool,
    optional_substrings: list[str] | None = None,
) -> float:
    optional_substrings = optional_substrings or []
    t0 = time.time()
    sock = client.connect()
    try:
        for idx, req in enumerate(reqs):
            path = path_of(req)
            require = not any(s in path for s in optional_substrings)
            acked = False
            for attempt in range(1, attempts + 1):
                log("WSS", f"write {idx+1}/{len(reqs)} try {attempt} {path}")
                try:
                    client.send_json(sock, [req])
                except Exception as e:  # noqa: BLE001
                    log("WSS", f"send fail: {e}")
                    client.close(sock)
                    time.sleep(1.0)
                    sock = client.connect()
                    continue
                rid = str(req.get("request_id", ""))
                deadline = time.time() + ack_wait
                got_ack = False
                idle_after = None
                while time.time() < deadline:
                    raw = client.recv_frame(sock, max(0.1, deadline - time.time()))
                    if raw is None:
                        if got_ack and idle_after is not None and (time.time() - idle_after) > 0.2:
                            break
                        continue
                    if raw == b"":
                        continue
                    text = raw.decode("utf-8", errors="replace")
                    log("WSS", f"frame preview={text[:200]}")
                    chunks, _ = extract_json_values(text)
                    for decoded in chunks:
                        for msg in flatten_messages(decoded):
                            if is_write_failure(msg):
                                raise RuntimeError(f"write rejected {path}: {msg}")
                            rid_ok = rid and str(msg.get("request_id", "")) == rid
                            path_ok = msg_mentions_path(msg, path)
                            success_ok = msg.get("success") is True
                            if path_specific:
                                if path_ok or rid_ok or success_ok:
                                    got_ack = True
                                    idle_after = time.time()
                            else:
                                got_ack = True
                                idle_after = time.time()
                if got_ack:
                    acked = True
                    break
            if not acked:
                if require:
                    raise RuntimeError(f"no ACK for {path}")
                log("WSS", f"optional no ACK: {path}")
            if idx < len(reqs) - 1:
                time.sleep(0.08 if ack_wait <= 1.6 else 0.12)
    finally:
        client.close(sock)
        time.sleep(0.4)
    return time.time() - t0


def verify_match(client: GardenaWss, device_id: str, expected: list[dict[str, Any]]) -> tuple[bool, list[dict[str, Any]], str]:
    time.sleep(1.2)
    devices = client.discover()
    data = devices.get(device_id)
    if not isinstance(data, dict):
        # try fuzzy find
        did, data = find_valve_device(devices)
        device_id = did
    actual = parse_slots(data)
    ok = rules_fingerprint(actual) == rules_fingerprint(expected)
    return ok, actual, rules_fingerprint(actual)


def restore_baseline(client: GardenaWss, device_id: str, baseline: list[dict[str, Any]]) -> None:
    log("RESTORE", f"writing {len(baseline)} slots")
    # Prefer path-specific short ACK (strategy C) — usually fastest reliable
    try:
        strategy_c_path_ack(client, device_id, baseline, ack_wait=1.5)
    except Exception as e:  # noqa: BLE001
        log("RESTORE", f"C failed ({e}), falling back to A")
        strategy_a_loose(client, device_id, baseline, ack_wait=4.0)
    ok, actual, fp = verify_match(client, device_id, baseline)
    log("RESTORE", f"ok={ok} fp={fp} actual={json.dumps(actual, ensure_ascii=False)}")
    if not ok:
        raise RuntimeError("baseline restore failed")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--host", default=os.environ.get("GARDENA_HOST", "172.18.1.189"))
    ap.add_argument("--password", default=os.environ.get("GARDENA_PASSWORD", ""))
    ap.add_argument("--port", type=int, default=8443)
    ap.add_argument("--mode", choices=["baseline", "strategies", "restore"], default="baseline")
    ap.add_argument("--baseline-file", default=os.path.join(os.path.dirname(__file__), "schedule_baseline.json"))
    args = ap.parse_args()
    if not args.password:
        print("password required via --password or GARDENA_PASSWORD", file=sys.stderr)
        return 2

    client = GardenaWss(args.host, args.password, args.port)
    devices = client.discover()
    device_id, data = find_valve_device(devices)
    rules = parse_slots(data)
    log("BASE", f"device={device_id}")
    log("BASE", f"slots={len(rules)}")
    print(json.dumps(rules, indent=2, ensure_ascii=False))

    if args.mode == "baseline":
        payload = {"device_id": device_id, "rules": rules, "saved_at": time.time()}
        with open(args.baseline_file, "w", encoding="utf-8") as f:
            json.dump(payload, f, indent=2)
        log("BASE", f"saved {args.baseline_file}")
        return 0

    with open(args.baseline_file, encoding="utf-8") as f:
        baseline_doc = json.load(f)
    baseline = baseline_doc["rules"]
    device_id = baseline_doc.get("device_id") or device_id
    prev_max = max((int(r["slot"]) for r in baseline), default=-1)

    if args.mode == "restore":
        restore_baseline(client, device_id, baseline)
        return 0

    # strategies mode
    mutated = mutate_rules(baseline, 15)
    log("TEST", f"baseline_fp={rules_fingerprint(baseline)}")
    log("TEST", f"mutated_fp={rules_fingerprint(mutated)}")

    results = []
    strategies = [
        ("A_loose_4s", lambda: strategy_a_loose(client, device_id, mutated, ack_wait=4.0)),
        ("C_path_1_5s", lambda: strategy_c_path_ack(client, device_id, mutated, ack_wait=1.5)),
        ("B_batch_slot", lambda: strategy_b_batch_slot(client, device_id, mutated)),
        ("D_alt_order_reptype", lambda: strategy_d_alt_order(client, device_id, mutated)),
        ("E_with_clear_ack", lambda: strategy_e_clear_acked(client, device_id, mutated, prev_max)),
    ]

    for name, fn in strategies:
        log("TEST", f"=== strategy {name} ===")
        # ensure known start state
        try:
            restore_baseline(client, device_id, baseline)
        except Exception as e:  # noqa: BLE001
            log("TEST", f"pre-restore failed: {e}")
        try:
            dur = fn()
            ok, actual, fp = verify_match(client, device_id, mutated)
            results.append({"strategy": name, "seconds": round(dur, 2), "verify_ok": ok, "actual_fp": fp})
            log("TEST", f"{name}: dur={dur:.2f}s verify={ok} fp={fp}")
        except Exception as e:  # noqa: BLE001
            results.append({"strategy": name, "seconds": None, "verify_ok": False, "error": str(e)})
            log("TEST", f"{name}: FAILED {e}")
        # restore after each
        try:
            restore_baseline(client, device_id, baseline)
        except Exception as e:  # noqa: BLE001
            log("TEST", f"post-restore failed: {e}")
            break

    print("=== RESULTS ===")
    print(json.dumps(results, indent=2))
    winners = [r for r in results if r.get("verify_ok")]
    if winners:
        winners.sort(key=lambda r: r["seconds"] if r["seconds"] is not None else 9999)
        log("TEST", f"fastest reliable: {winners[0]['strategy']} ({winners[0]['seconds']}s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
