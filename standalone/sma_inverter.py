"""
SMA Sunny Tripower Inverter Reader

Reads current AC power output from SMA inverters.
Supports two methods:
  - Modbus TCP (port 502, register 30775)
  - WebConnect HTTP (fallback, needs password)

Key register:
  30775 (GridMs.TotW) — current AC power output in Watts (S32, 2 registers)
"""

import struct
import time
from dataclasses import dataclass

import requests
from pymodbus.client import ModbusTcpClient

MODBUS_PORT = 502
MODBUS_UNIT_ID = 3
REGISTER_AC_POWER = 30775
REGISTER_COUNT = 2

NAN_S32 = 0x80000000
WEBCONNECT_KEY_POWER = "6100_40263F00"


@dataclass
class InverterData:
    ip: str
    name: str
    power_w: float
    reachable: bool
    method: str
    timestamp: float


def read_modbus(ip: str, name: str, port: int = MODBUS_PORT, unit_id: int = MODBUS_UNIT_ID) -> InverterData:
    """Read current AC power via Modbus TCP."""
    client = ModbusTcpClient(ip, port=port, timeout=3)
    try:
        if not client.connect():
            return InverterData(ip=ip, name=name, power_w=0, reachable=False, method="modbus", timestamp=time.time())

        result = client.read_input_registers(
            address=REGISTER_AC_POWER,
            count=REGISTER_COUNT,
            device_id=unit_id,
        )

        if result.isError():
            return InverterData(ip=ip, name=name, power_w=0, reachable=False, method="modbus", timestamp=time.time())

        raw = (result.registers[0] << 16) | result.registers[1]
        if raw == NAN_S32 or raw == 0xFFFFFFFF:
            power = 0.0
        else:
            power = float(struct.unpack(">i", struct.pack(">I", raw))[0])

        return InverterData(
            ip=ip, name=name, power_w=max(0, power),
            reachable=True, method="modbus", timestamp=time.time(),
        )
    except Exception:
        return InverterData(ip=ip, name=name, power_w=0, reachable=False, method="modbus", timestamp=time.time())
    finally:
        client.close()


def read_webconnect(ip: str, name: str, password: str, right: str = "usr") -> InverterData:
    """Read current AC power via WebConnect HTTP API (fallback)."""
    base = f"https://{ip}"
    session = requests.Session()
    session.verify = False

    try:
        # Login
        login_resp = session.post(
            f"{base}/dyn/login.json",
            json={"right": right, "pass": password},
            timeout=5,
        )
        login_data = login_resp.json()
        sid = login_data.get("result", {}).get("sid")
        if not sid:
            return InverterData(ip=ip, name=name, power_w=0, reachable=False, method="webconnect", timestamp=time.time())

        # Read power value
        val_resp = session.post(
            f"{base}/dyn/getValues.json?sid={sid}",
            json={"destDev": [], "keys": [WEBCONNECT_KEY_POWER]},
            timeout=5,
        )
        val_data = val_resp.json()

        # Logout
        session.post(f"{base}/dyn/logout.json?sid={sid}", timeout=3)

        # Parse: {"result": {"<serial>": {"6100_40263F00": {"1": [{"val": 1234}]}}}}
        power = 0.0
        result = val_data.get("result", {})
        for device_data in result.values():
            key_data = device_data.get(WEBCONNECT_KEY_POWER, {})
            for channel in key_data.values():
                if isinstance(channel, list) and channel:
                    val = channel[0].get("val")
                    if val is not None and val != 0xFFFFFF80:
                        power = float(val)

        return InverterData(
            ip=ip, name=name, power_w=max(0, power),
            reachable=True, method="webconnect", timestamp=time.time(),
        )
    except Exception:
        return InverterData(ip=ip, name=name, power_w=0, reachable=False, method="webconnect", timestamp=time.time())


def read_inverter(inv: dict) -> InverterData:
    """Read a single inverter, trying Modbus first, then WebConnect."""
    ip = inv["ip"]
    name = inv.get("name", ip)
    unit_id = inv.get("unit_id", MODBUS_UNIT_ID)

    port = inv.get("port", MODBUS_PORT)
    data = read_modbus(ip, name, port, unit_id)
    if data.reachable:
        return data

    password = inv.get("password")
    if password:
        return read_webconnect(ip, name, password)

    return data


def read_all_inverters(inverters: list[dict]) -> tuple[float, list[InverterData]]:
    """
    Read power from all configured inverters.

    Args:
        inverters: list of dicts with keys:
            ip (required), name, unit_id, password (for WebConnect fallback)

    Returns:
        (total_power_w, [InverterData, ...])
    """
    results = []
    total = 0.0
    for inv in inverters:
        data = read_inverter(inv)
        results.append(data)
        if data.reachable:
            total += data.power_w
    return total, results


if __name__ == "__main__":
    test_inverters = [
        {"ip": "172.18.1.156", "name": "Sunny 8", "port": 502},
        {"ip": "172.18.1.173", "name": "Sunny 10", "port": 503, "unit_id": 4},
    ]

    print("Lese Wechselrichter-Daten...\n")
    total, results = read_all_inverters(test_inverters)

    for inv in results:
        if inv.reachable:
            print(f"  {inv.name} ({inv.ip}): {inv.power_w:.0f} W  [{inv.method}]")
        else:
            print(f"  {inv.name} ({inv.ip}): NICHT ERREICHBAR  [{inv.method}]")

    print(f"\n  Gesamt-Erzeugung: {total:.0f} W")
