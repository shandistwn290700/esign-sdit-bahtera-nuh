"""SQLite persistence for signer key metadata, CA material and the operation log.

Holds no documents. The database lives on the same encrypted-backup volume as the HSM token.
"""

from __future__ import annotations

import sqlite3
import threading
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

_SCHEMA = """
CREATE TABLE IF NOT EXISTS signers (
    key_id TEXT PRIMARY KEY,
    signer_ref TEXT NOT NULL,
    cert_der BLOB NOT NULL,
    serial_hex TEXT NOT NULL UNIQUE,
    revoked_at TEXT,
    revocation_reason TEXT,
    totp_secret_enc BLOB NOT NULL,
    totp_status TEXT NOT NULL,
    totp_last_step INTEGER,
    fail_count INTEGER NOT NULL DEFAULT 0,
    fail_window_start REAL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS signers_ref ON signers(signer_ref);
CREATE TABLE IF NOT EXISTS system (
    name TEXT PRIMARY KEY,
    value BLOB NOT NULL
);
CREATE TABLE IF NOT EXISTS ops_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    at TEXT NOT NULL,
    request_id TEXT,
    op TEXT NOT NULL,
    key_id TEXT,
    actor_ref TEXT,
    input_sha256 TEXT,
    output_sha256 TEXT,
    result TEXT NOT NULL
);
CREATE TRIGGER IF NOT EXISTS ops_log_no_update BEFORE UPDATE ON ops_log
BEGIN SELECT RAISE(ABORT, 'ops_log is append-only'); END;
CREATE TRIGGER IF NOT EXISTS ops_log_no_delete BEFORE DELETE ON ops_log
BEGIN SELECT RAISE(ABORT, 'ops_log is append-only'); END;
"""

_UPDATABLE = {
    "revoked_at",
    "revocation_reason",
    "totp_secret_enc",
    "totp_status",
    "totp_last_step",
    "fail_count",
    "fail_window_start",
}


def _now_iso() -> str:
    return datetime.now(UTC).isoformat()


@dataclass(frozen=True)
class SignerRecord:
    key_id: str
    signer_ref: str
    cert_der: bytes
    serial_hex: str
    revoked_at: datetime | None
    revocation_reason: str | None
    totp_secret_enc: bytes
    totp_status: str
    totp_last_step: int | None
    fail_count: int
    fail_window_start: float | None
    created_at: datetime


def _record(row: sqlite3.Row) -> SignerRecord:
    return SignerRecord(
        key_id=row["key_id"],
        signer_ref=row["signer_ref"],
        cert_der=row["cert_der"],
        serial_hex=row["serial_hex"],
        revoked_at=datetime.fromisoformat(row["revoked_at"]) if row["revoked_at"] else None,
        revocation_reason=row["revocation_reason"],
        totp_secret_enc=row["totp_secret_enc"],
        totp_status=row["totp_status"],
        totp_last_step=row["totp_last_step"],
        fail_count=row["fail_count"],
        fail_window_start=row["fail_window_start"],
        created_at=datetime.fromisoformat(row["created_at"]),
    )


class Store:
    """Thread-safe wrapper around a single SQLite connection."""

    def __init__(self, path: Path | str) -> None:
        self._conn = sqlite3.connect(str(path), check_same_thread=False, isolation_level=None)
        self._conn.row_factory = sqlite3.Row
        self._lock = threading.Lock()
        with self._lock:
            self._conn.execute("PRAGMA journal_mode=WAL")
            self._conn.executescript(_SCHEMA)

    def close(self) -> None:
        self._conn.close()

    def _one(self, sql: str, params: tuple[Any, ...]) -> sqlite3.Row | None:
        with self._lock:
            row: sqlite3.Row | None = self._conn.execute(sql, params).fetchone()
            return row

    def insert_signer(
        self,
        *,
        key_id: str,
        signer_ref: str,
        cert_der: bytes,
        serial_hex: str,
        totp_secret_enc: bytes,
    ) -> None:
        with self._lock:
            self._conn.execute(
                "INSERT INTO signers (key_id, signer_ref, cert_der, serial_hex, totp_secret_enc,"
                " totp_status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', ?)",
                (key_id, signer_ref, cert_der, serial_hex, totp_secret_enc, _now_iso()),
            )

    def get_signer(self, key_id: str) -> SignerRecord | None:
        row = self._one("SELECT * FROM signers WHERE key_id = ?", (key_id,))
        return _record(row) if row else None

    def get_signer_by_serial(self, serial_hex: str) -> SignerRecord | None:
        row = self._one("SELECT * FROM signers WHERE serial_hex = ?", (serial_hex,))
        return _record(row) if row else None

    def find_unrevoked_by_ref(self, signer_ref: str) -> SignerRecord | None:
        row = self._one(
            "SELECT * FROM signers WHERE signer_ref = ? AND revoked_at IS NULL "
            "ORDER BY created_at DESC LIMIT 1",
            (signer_ref,),
        )
        return _record(row) if row else None

    def update_signer(self, key_id: str, **changes: Any) -> None:
        unknown = set(changes) - _UPDATABLE
        if unknown:
            raise ValueError(f"cannot update columns: {sorted(unknown)}")
        if isinstance(changes.get("revoked_at"), datetime):
            changes["revoked_at"] = changes["revoked_at"].isoformat()
        columns = ", ".join(f"{name} = ?" for name in changes)
        with self._lock:
            self._conn.execute(
                f"UPDATE signers SET {columns} WHERE key_id = ?",  # noqa: S608 (whitelisted)
                (*changes.values(), key_id),
            )

    def list_revoked(self) -> list[SignerRecord]:
        with self._lock:
            rows = self._conn.execute(
                "SELECT * FROM signers WHERE revoked_at IS NOT NULL ORDER BY revoked_at"
            ).fetchall()
        return [_record(r) for r in rows]

    def get_system(self, name: str) -> bytes | None:
        row = self._one("SELECT value FROM system WHERE name = ?", (name,))
        return bytes(row["value"]) if row else None

    def set_system(self, name: str, value: bytes) -> None:
        with self._lock:
            self._conn.execute(
                "INSERT INTO system (name, value) VALUES (?, ?) "
                "ON CONFLICT(name) DO UPDATE SET value = excluded.value",
                (name, value),
            )

    def log_op(
        self,
        op: str,
        result: str,
        *,
        request_id: str | None = None,
        key_id: str | None = None,
        actor_ref: str | None = None,
        input_sha256: str | None = None,
        output_sha256: str | None = None,
    ) -> None:
        with self._lock:
            self._conn.execute(
                "INSERT INTO ops_log (at, request_id, op, key_id, actor_ref, input_sha256,"
                " output_sha256, result) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                (
                    _now_iso(),
                    request_id,
                    op,
                    key_id,
                    actor_ref,
                    input_sha256,
                    output_sha256,
                    result,
                ),
            )
