"""
Modbus TCP Diagnose-Script für SMA Wechselrichter.

Testet Erreichbarkeit, verschiedene Unit-IDs, Register-Typen und Adressen.
"""

import socket
import sys
from pymodbus.client import ModbusTcpClient

INVERTERS = [
    ("Sunny 8", "172.18.1.156"),
    ("Sunny 10", "172.18.1.173"),
]

UNIT_IDS = [3, 126, 1, 2]

REGISTERS = [
    ("30775 GridMs.TotW (AC Power)",   30775),
    ("30773 GridMs.TotW (alt offset)", 30773),
    ("40631 Pac (Wirkleistung)",       40631),
]


def test_tcp(ip: str, port: int = 502, timeout: float = 3) -> bool:
    """Raw TCP connect test."""
    try:
        s = socket.create_connection((ip, port), timeout=timeout)
        s.close()
        return True
    except (socket.timeout, ConnectionRefusedError, OSError):
        return False


def read_reg(client, address: int, count: int, device_id: int, func: str) -> str:
    """Try reading registers, return result string."""
    try:
        if func == "input":
            result = client.read_input_registers(address=address, count=count, device_id=device_id)
        else:
            result = client.read_holding_registers(address=address, count=count, device_id=device_id)

        if result.isError():
            return f"FEHLER ({result})"

        raw = (result.registers[0] << 16) | result.registers[1]
        if raw == 0x80000000 or raw == 0xFFFFFFFF:
            return f"NaN (raw=0x{raw:08x})"
        import struct
        signed = struct.unpack(">i", struct.pack(">I", raw))[0]
        return f"raw={raw} signed={signed} => {signed} W"

    except Exception as e:
        return f"Exception: {e}"


def test_modbus(ip: str, unit_id: int, register: int, name: str):
    client = ModbusTcpClient(ip, port=502, timeout=3)
    if not client.connect():
        print(f"      Modbus connect fehlgeschlagen")
        return
    try:
        for addr_label, addr in [("0-based", register - 1), ("direkt", register)]:
            for func in ["input", "holding"]:
                res = read_reg(client, addr, 2, unit_id, func)
                print(f"      {func:8s} addr={addr:5d} ({addr_label:7s}) unit={unit_id}: {res}")
    finally:
        client.close()


def main():
    for name, ip in INVERTERS:
        print(f"\n{'='*60}")
        print(f"  {name} ({ip})")
        print(f"{'='*60}")

        # 1. TCP Erreichbarkeit
        tcp_ok = test_tcp(ip, 502)
        print(f"\n  [TCP] Port 502 erreichbar: {'JA' if tcp_ok else 'NEIN'}")

        if not tcp_ok:
            tcp_80 = test_tcp(ip, 80)
            tcp_443 = test_tcp(ip, 443)
            print(f"  [TCP] Port 80 (HTTP):      {'JA' if tcp_80 else 'NEIN'}")
            print(f"  [TCP] Port 443 (HTTPS):    {'JA' if tcp_443 else 'NEIN'}")
            if not tcp_80 and not tcp_443:
                print(f"  => Gerät scheint nicht erreichbar. Netzwerk/Firewall prüfen.")
            else:
                print(f"  => Gerät erreichbar, aber Modbus Port 502 geschlossen.")
                print(f"     Modbus TCP im Wechselrichter aktivieren (Sunny Explorer / Webinterface)")
            continue

        # 2. Modbus Register testen
        print(f"\n  [MODBUS] Teste Register mit verschiedenen Unit-IDs...\n")
        for reg_name, reg_addr in REGISTERS:
            print(f"    {reg_name}:")
            for unit_id in UNIT_IDS:
                test_modbus(ip, unit_id, reg_addr, reg_name)
            print()

    print("\nDiagnose abgeschlossen.")


if __name__ == "__main__":
    main()
