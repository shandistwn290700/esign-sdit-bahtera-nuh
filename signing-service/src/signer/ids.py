"""Identifier helpers."""

from __future__ import annotations

import os
import time

_CROCKFORD = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"


def new_ulid(now_ms: int | None = None) -> str:
    """Return a 26-character Crockford base32 ULID (48-bit time + 80-bit randomness)."""
    timestamp = int(time.time() * 1000) if now_ms is None else now_ms
    value = (timestamp << 80) | int.from_bytes(os.urandom(10), "big")
    chars = []
    for _ in range(26):
        chars.append(_CROCKFORD[value & 31])
        value >>= 5
    return "".join(reversed(chars))


def new_key_id() -> str:
    return f"sk_{new_ulid()}"
