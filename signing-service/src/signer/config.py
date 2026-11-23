"""Runtime configuration, read from SIGNER_* environment variables."""

from __future__ import annotations

import base64
import binascii
from functools import lru_cache
from pathlib import Path

from pydantic import SecretStr
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Service settings. Secrets come from Coolify environment secrets, never from the repo."""

    model_config = SettingsConfigDict(env_prefix="SIGNER_")

    app_env: str = "production"
    api_tokens: SecretStr
    data_dir: Path = Path("/data")
    pkcs11_lib: Path = Path("/usr/lib/softhsm/libsofthsm2.so")
    hsm_token_label: str = "tte"  # noqa: S105 (token label, not a secret)
    hsm_pin: SecretStr
    totp_encryption_key: SecretStr
    tsa_url: str | None = "http://timestamp.digicert.com"
    require_timestamp: bool = True
    crl_public_url: str
    org_name: str
    country: str = "ID"
    max_pdf_bytes: int = 20 * 1024 * 1024
    signer_cert_days: int = 730
    # Time shown on the QR stamp (WIB = +7, WITA = +8, WIT = +9).
    stamp_utc_offset_hours: int = 7
    stamp_timezone_label: str = "WIB"

    def token_list(self) -> list[bytes]:
        """Return the accepted API tokens (comma separated, at least 32 characters each)."""
        tokens = [t.strip() for t in self.api_tokens.get_secret_value().split(",") if t.strip()]
        if not tokens or any(len(t) < 32 for t in tokens):
            raise ValueError("SIGNER_API_TOKENS must contain tokens of at least 32 characters")
        return [t.encode() for t in tokens]

    def totp_key(self) -> bytes:
        """Return the 32-byte AES key used to encrypt TOTP secrets at rest."""
        try:
            key = base64.b64decode(self.totp_encryption_key.get_secret_value(), validate=True)
        except binascii.Error as exc:
            raise ValueError("SIGNER_TOTP_ENCRYPTION_KEY must be base64") from exc
        if len(key) != 32:
            raise ValueError("SIGNER_TOTP_ENCRYPTION_KEY must decode to 32 bytes")
        return key

    @property
    def db_path(self) -> Path:
        return self.data_dir / "signer.sqlite3"


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()  # type: ignore[call-arg]
