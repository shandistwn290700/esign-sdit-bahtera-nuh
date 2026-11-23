"""End-to-end smoke test against a running signer (real SoftHSM + real TSA).

Run inside the signer container (stdin), because the service has no published port:
    docker compose -f compose.dev.yml exec -T signer python - < signing-service/scripts/smoke.py
Takes ~35 s: it waits for a fresh TOTP step between activation and signing.
"""

from __future__ import annotations

import hashlib
import json
import os
import time
import urllib.error
import urllib.request
import uuid
from urllib.parse import parse_qs, urlparse

import pyotp

BASE = "http://127.0.0.1:8000"
TOKEN = os.environ["SIGNER_API_TOKENS"].split(",")[0]


def call(method: str, path: str, *, body: bytes | None = None, ctype: str | None = None):
    req = urllib.request.Request(BASE + path, data=body, method=method)
    req.add_header("Authorization", f"Bearer {TOKEN}")
    req.add_header("X-Request-Id", "smoke-" + uuid.uuid4().hex[:8])
    if ctype:
        req.add_header("Content-Type", ctype)
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            return resp.status, {k.lower(): v for k, v in resp.headers.items()}, resp.read()
    except urllib.error.HTTPError as err:
        return err.code, {k.lower(): v for k, v in err.headers.items()}, err.read()


def multipart(pdf: bytes, meta: dict | None) -> tuple[bytes, str]:
    boundary = uuid.uuid4().hex
    parts = [
        f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="d.pdf"\r\n'
        "Content-Type: application/pdf\r\n\r\n".encode()
        + pdf
        + b"\r\n"
    ]
    if meta is not None:
        parts.append(
            f'--{boundary}\r\nContent-Disposition: form-data; name="meta"\r\n\r\n'.encode()
            + json.dumps(meta).encode()
            + b"\r\n"
        )
    return b"".join(
        parts
    ) + f"--{boundary}--\r\n".encode(), f"multipart/form-data; boundary={boundary}"


def make_pdf() -> bytes:
    objs = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << >> >>",
    ]
    out = bytearray(b"%PDF-1.7\n")
    offsets = []
    for n, body in enumerate(objs, 1):
        offsets.append(len(out))
        out += f"{n} 0 obj\n".encode() + body + b"\nendobj\n"
    xref = len(out)
    out += f"xref\n0 {len(objs) + 1}\n0000000000 65535 f \n".encode()
    out += b"".join(f"{o:010d} 00000 n \n".encode() for o in offsets)
    out += f"trailer\n<< /Size {len(objs) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode()
    return bytes(out)


def step(name: str, ok: bool, detail: object = "") -> None:
    print(f"[{'OK ' if ok else 'FAIL'}] {name} {detail}")
    if not ok:
        raise SystemExit(1)


status, _, body = call(
    "POST",
    "/v1/signers",
    body=json.dumps(
        {
            "signer_ref": str(uuid.uuid4()),
            "full_name": "Budi Santoso",
            "position": "Kepala Sekolah",
            "email": "kepsek@example.sch.id",
            "nip": "198001012005011001",
        }
    ).encode(),
    ctype="application/json",
)
step("create signer (key generated in SoftHSM)", status == 201, status)
signer = json.loads(body)
key_id = signer["key_id"]
secret = parse_qs(urlparse(signer["totp_enrollment"]["otpauth_uri"]).query)["secret"][0]

status, _, body = call(
    "POST",
    f"/v1/signers/{key_id}/totp/confirm",
    body=json.dumps({"totp_code": pyotp.TOTP(secret).now()}).encode(),
    ctype="application/json",
)
step("confirm TOTP", status == 200, status)

pdf = make_pdf()
meta = {
    "input_sha256": hashlib.sha256(pdf).hexdigest(),
    "fields": [
        {"name": "sig_1", "order": 1, "page": 1, "x": 60, "y": 60, "width": 200, "height": 100}
    ],
}
data, ctype = multipart(pdf, meta)
status, _, prepared = call("POST", "/v1/documents/prepare", body=data, ctype=ctype)
step("prepare fields", status == 200, status)

wait = 31 - int(time.time()) % 30
print(f"       waiting {wait}s for a fresh TOTP step...")
time.sleep(wait)

sign_meta = {
    "input_sha256": hashlib.sha256(prepared).hexdigest(),
    "field_name": "sig_1",
    "key_id": key_id,
    "totp_code": pyotp.TOTP(secret).now(),
    "verify_url": "https://verifikasi.example.sch.id/v/smoke",
    "certify": True,
    "appearance": {
        "name": "Budi Santoso",
        "position": "Kepala Sekolah",
        "nip": "198001012005011001",
    },
    "reason": "Menyetujui dokumen",
}
data, ctype = multipart(prepared, sign_meta)
status, headers, signed = call("POST", "/v1/documents/sign", body=data, ctype=ctype)
step(
    "sign with SoftHSM + TSA",
    status == 200,
    status if status != 200 else headers.get("x-signed-at"),
)
with open("/tmp/smoke-signed.pdf", "wb") as fh:
    fh.write(signed)

data, ctype = multipart(signed, None)
status, _, body = call("POST", "/v1/documents/validate", body=data, ctype=ctype)
report = json.loads(body)
sig = report["signatures"][0] if report.get("signatures") else {}
step(
    "validate",
    status == 200 and report.get("status") == "valid",
    {
        k: sig.get(k)
        for k in ("valid", "intact", "trusted", "timestamp_valid", "signed_at", "problems")
    },
)

status, _, body = call(
    "POST",
    "/v1/attestations/audit-anchor",
    body=json.dumps(
        {"chain_head_sha256": "a" * 64, "last_entry_id": 1, "anchored_date": "2026-11-21"}
    ).encode(),
    ctype="application/json",
)
step("audit anchor (CMS + TSA)", status == 200, status)
print("Signed PDF written to /tmp/smoke-signed.pdf inside the container.")
