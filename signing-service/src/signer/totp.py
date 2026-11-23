"""TOTP helpers with replay protection and authenticated encryption of secrets at rest."""

from __future__ import annotations

import hmac
import os
from enum import Enum

import pyotp
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

STEP_SECONDS = 30
MAX_FAILURES = 5
LOCKOUT_SECONDS = 15 * 60


class TotpResult(Enum):
    OK = "ok"
    INVALID = "invalid"
    REPLAYED = "replayed"


def new_secret() -> str:
    return pyotp.random_base32()


def provisioning_uri(secret: str, account_name: str, issuer: str) -> str:
    return pyotp.TOTP(secret).provisioning_uri(name=account_name, issuer_name=issuer)


def check_code(
    secret: str, code: str, *, now: float, last_used_step: int | None, window: int = 1
) -> tuple[TotpResult, int | None]:
    """Check a 6-digit code against the current step +/- `window`.

    Returns:
        (OK, step) when the code matches a step newer than `last_used_step`,
        (REPLAYED, step) when it matches an already-used step, otherwise (INVALID, None).
    """
    totp = pyotp.TOTP(secret)
    current = int(now // STEP_SECONDS)
    for step in range(current - window, current + window + 1):
        if hmac.compare_digest(totp.at(step * STEP_SECONDS), code):
            if last_used_step is not None and step <= last_used_step:
                return TotpResult.REPLAYED, step
            return TotpResult.OK, step
    return TotpResult.INVALID, None


class SecretBox:
    """AES-256-GCM with the key id as associated data, so ciphertexts cannot be swapped."""

    def __init__(self, key: bytes) -> None:
        if len(key) != 32:
            raise ValueError("key must be 32 bytes")
        self._aead = AESGCM(key)

    def seal(self, plaintext: bytes, aad: bytes) -> bytes:
        nonce = os.urandom(12)
        return nonce + self._aead.encrypt(nonce, plaintext, aad)

    def open(self, blob: bytes, aad: bytes) -> bytes:
        return self._aead.decrypt(blob[:12], blob[12:], aad)
