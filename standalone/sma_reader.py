"""
SMA Home Manager 2 Speedwire Reader

Receives energy data from SMA Home Manager 2 via Speedwire multicast protocol.
The HM2 broadcasts UDP packets once per second to 239.12.255.254:9522.
"""

import socket
import struct
import time
from dataclasses import dataclass

MULTICAST_IP = "239.12.255.254"
MULTICAST_PORT = 9522

SMA_SIGNATURE = b"SMA\x00"
SPEEDWIRE_PROTOCOL_ID = 0x6069


@dataclass
class EnergyData:
    """Parsed energy measurements from the SMA Home Manager 2."""
    timestamp: float
    serial: str
    power_buy_w: float    # Grid consumption (Watts)
    power_sell_w: float   # Grid feed-in (Watts)
    power_net_w: float    # Net power: positive = consuming, negative = feeding in
    l1_buy_w: float
    l1_sell_w: float
    l2_buy_w: float
    l2_sell_w: float
    l3_buy_w: float
    l3_sell_w: float

    @property
    def consuming(self) -> bool:
        return self.power_net_w > 0

    @property
    def display_power_w(self) -> float:
        return abs(self.power_net_w)


def _read_u16(data: bytes, offset: int) -> int:
    return int.from_bytes(data[offset : offset + 2], byteorder="big")


def _read_u32(data: bytes, offset: int) -> int:
    return int.from_bytes(data[offset : offset + 4], byteorder="big")


def _decode_phase(data: bytes) -> tuple[float, float]:
    buy = _read_u16(data, 6) / 10.0
    sell = _read_u16(data, 26) / 10.0
    return buy, sell


def decode_speedwire(data: bytes) -> EnergyData | None:
    if len(data) < 600:
        return None

    if data[0:4] != SMA_SIGNATURE:
        return None

    serial_bytes = data[20:24]
    serial = serial_bytes.hex()

    power_buy = _read_u32(data, 32) / 10.0
    power_sell = _read_u32(data, 52) / 10.0

    l1_buy, l1_sell = _decode_phase(data[164:308])
    l2_buy, l2_sell = _decode_phase(data[308:452])
    l3_buy, l3_sell = _decode_phase(data[452:596])

    return EnergyData(
        timestamp=time.time(),
        serial=serial,
        power_buy_w=power_buy,
        power_sell_w=power_sell,
        power_net_w=power_buy - power_sell,
        l1_buy_w=l1_buy,
        l1_sell_w=l1_sell,
        l2_buy_w=l2_buy,
        l2_sell_w=l2_sell,
        l3_buy_w=l3_buy,
        l3_sell_w=l3_sell,
    )


def create_socket(bind_address: str = "") -> socket.socket:
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    sock.bind((bind_address, MULTICAST_PORT))

    mreq = struct.pack("4sl", socket.inet_aton(MULTICAST_IP), socket.INADDR_ANY)
    sock.setsockopt(socket.IPPROTO_IP, socket.IP_ADD_MEMBERSHIP, mreq)
    sock.settimeout(10)

    return sock


def read_once(sock: socket.socket) -> EnergyData | None:
    """Read a single Speedwire packet and return parsed data."""
    try:
        data, addr = sock.recvfrom(10240)
        return decode_speedwire(data)
    except socket.timeout:
        return None


if __name__ == "__main__":
    print("Warte auf SMA Home Manager 2 Speedwire-Daten...")
    sock = create_socket()
    while True:
        result = read_once(sock)
        if result:
            direction = "Bezug" if result.consuming else "Einspeisung"
            print(
                f"{direction}: {result.display_power_w:.0f} W  "
                f"(Bezug: {result.power_buy_w:.0f} W | "
                f"Einspeisung: {result.power_sell_w:.0f} W)"
            )
