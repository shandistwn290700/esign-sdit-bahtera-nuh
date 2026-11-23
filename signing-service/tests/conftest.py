from __future__ import annotations

import base64
import hashlib
import json
import os
from collections.abc import Iterator
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import parse_qs, urlparse
from uuid import uuid4

import pyotp
import pytest
from fastapi.testclient import TestClient

from signer.app import create_app
from signer.bootstrap import init_dev_ca
from signer.config import Settings
from signer.keystore import SoftKeyStore
from signer.pki import CertificateAuthority
from signer.services import Services
from signer.store import Store
from signer.totp import SecretBox

TOKEN = "t" * 40


def make_pdf(pages: int = 1) -> bytes:
    """Build a minimal valid PDF with A4 pages and a correct xref table."""
    objects = [b"<< /Type /Catalog /Pages 2 0 R >>"]
    kids = " ".join(f"{3 + i} 0 R" for i in range(pages))
    objects.append(f"<< /Type /Pages /Kids [{kids}] /Count {pages} >>".encode())
    objects += [b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << >> >>"] * pages
    out = bytearray(b"%PDF-1.7\n%\xe2\xe3\xcf\xd3\n")
    offsets = []
    for number, body in enumerate(objects, start=1):
        offsets.append(len(out))
        out += f"{number} 0 obj\n".encode() + body + b"\nendobj\n"
    xref = len(out)
    out += f"xref\n0 {len(objects) + 1}\n0000000000 65535 f \n".encode()
    out += b"".join(f"{o:010d} 00000 n \n".encode() for o in offsets)
    out += (
        f"trailer\n<< /Size {len(objects) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode()
    )
    return bytes(out)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


class Clock:
    def __init__(self) -> None:
        self.now = 1_800_000_000.0

    def __call__(self) -> float:
        return self.now

    def tick(self, seconds: float = 30) -> None:
        self.now += seconds


@dataclass
class Env:
    client: TestClient
    svc: Services
    clock: Clock

    @property
    def auth(self) -> dict[str, str]:
        return {"Authorization": f"Bearer {TOKEN}"}

    def code(self, secret: str) -> str:
        return pyotp.TOTP(secret).at(int(self.clock.now))

    def new_signer(self, name: str = "Budi Santoso") -> tuple[str, str]:
        """Create and activate a signer; return (key_id, totp_secret)."""
        resp = self.client.post(
            "/v1/signers",
            headers=self.auth,
            json={
                "signer_ref": str(uuid4()),
                "full_name": name,
                "position": "Kepala Sekolah",
                "email": "kepsek@example.sch.id",
                "nip": "198001012005011001",
            },
        )
        assert resp.status_code == 201, resp.text
        body = resp.json()
        secret = parse_qs(urlparse(body["totp_enrollment"]["otpauth_uri"]).query)["secret"][0]
        confirm = self.client.post(
            f"/v1/signers/{body['key_id']}/totp/confirm",
            headers=self.auth,
            json={"totp_code": self.code(secret)},
        )
        assert confirm.status_code == 200, confirm.text
        self.clock.tick()
        return body["key_id"], secret

    def prepare(self, pdf: bytes, signers: int) -> bytes:
        fields = [
            {
                "name": f"sig_{i}",
                "order": i,
                "page": 1,
                "x": 50 + (i - 1) * 180,
                "y": 60,
                "width": 160,
                "height": 90,
            }
            for i in range(1, signers + 1)
        ]
        resp = self.client.post(
            "/v1/documents/prepare",
            headers=self.auth,
            files={"file": ("doc.pdf", pdf, "application/pdf")},
            data={"meta": json.dumps({"input_sha256": sha(pdf), "fields": fields})},
        )
        assert resp.status_code == 200, resp.text
        return resp.content

    def sign(self, pdf: bytes, field: str, key_id: str, code: str, **extra: object) -> object:
        meta = {
            "input_sha256": sha(pdf),
            "field_name": field,
            "key_id": key_id,
            "totp_code": code,
            "verify_url": "https://verifikasi.example.sch.id/v/abc123",
            "certify": field == "sig_1",
            "appearance": {"name": "Budi Santoso", "position": "Kepala Sekolah"},
            **extra,
        }
        return self.client.post(
            "/v1/documents/sign",
            headers=self.auth,
            files={"file": ("doc.pdf", pdf, "application/pdf")},
            data={"meta": json.dumps(meta)},
        )

    def validate(self, pdf: bytes) -> dict[str, object]:
        resp = self.client.post(
            "/v1/documents/validate",
            headers=self.auth,
            files={"file": ("doc.pdf", pdf, "application/pdf")},
        )
        assert resp.status_code == 200, resp.text
        result: dict[str, object] = resp.json()
        return result


@pytest.fixture
def env(tmp_path: Path) -> Iterator[Env]:
    settings = Settings(
        app_env="test",
        api_tokens=TOKEN,
        data_dir=tmp_path,
        hsm_pin="unused",
        totp_encryption_key=base64.b64encode(os.urandom(32)).decode(),
        tsa_url=None,
        require_timestamp=False,
        crl_public_url="https://verifikasi.example.sch.id/pki/crl",
        org_name="SMA Contoh",
    )
    store = Store(settings.db_path)
    keys = SoftKeyStore()
    ca = CertificateAuthority(
        keys, store, org_name=settings.org_name, country="ID", crl_url=settings.crl_public_url
    )
    clock = Clock()
    svc = Services(
        settings=settings,
        store=store,
        keys=keys,
        ca=ca,
        box=SecretBox(settings.totp_key()),
        clock=clock,
    )
    assert init_dev_ca(svc)
    with TestClient(create_app(svc)) as client:
        yield Env(client=client, svc=svc, clock=clock)
    svc.close()
