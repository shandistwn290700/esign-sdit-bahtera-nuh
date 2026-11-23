from __future__ import annotations

import os
import re
from datetime import UTC, datetime, timedelta

import pyotp
import pytest
from asn1crypto import crl as asn1_crl
from asn1crypto import x509
from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.hazmat.primitives.serialization import load_der_public_key
from pydantic import ValidationError

from signer.ids import new_key_id
from signer.models import FieldPlacement, PrepareMeta, SignMeta
from signer.pki import RevokedEntry, build_crl, issue_certificate
from signer.totp import SecretBox, TotpResult, check_code


def test_key_id_format() -> None:
    assert re.fullmatch(r"sk_[0-9A-HJKMNP-TV-Z]{26}", new_key_id())


class TestTotp:
    secret = pyotp.random_base32()
    now = 1_800_000_000.0

    def test_accepts_current_code(self) -> None:
        code = pyotp.TOTP(self.secret).at(int(self.now))
        result, step = check_code(self.secret, code, now=self.now, last_used_step=None)
        assert result is TotpResult.OK
        assert step == int(self.now // 30)

    def test_accepts_previous_step(self) -> None:
        code = pyotp.TOTP(self.secret).at(int(self.now) - 30)
        assert check_code(self.secret, code, now=self.now, last_used_step=None)[0] is TotpResult.OK

    def test_rejects_replay(self) -> None:
        code = pyotp.TOTP(self.secret).at(int(self.now))
        step = int(self.now // 30)
        result, _ = check_code(self.secret, code, now=self.now, last_used_step=step)
        assert result is TotpResult.REPLAYED

    def test_rejects_stale(self) -> None:
        stale = pyotp.TOTP(self.secret).at(int(self.now) - 300)
        assert check_code(self.secret, stale, now=self.now, last_used_step=None)[0] is (
            TotpResult.INVALID
        )


def test_secret_box_binds_aad() -> None:
    box = SecretBox(os.urandom(32))
    blob = box.seal(b"secret", b"sk_A")
    assert box.open(blob, b"sk_A") == b"secret"
    with pytest.raises(InvalidTag):
        box.open(blob, b"sk_B")


def _soft_signer() -> tuple[ec.EllipticCurvePrivateKey, bytes]:
    from cryptography.hazmat.primitives import serialization

    key = ec.generate_private_key(ec.SECP384R1())
    spki = key.public_key().public_bytes(
        serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo
    )
    return key, spki


def test_certificate_chain_signatures_verify() -> None:
    now = datetime.now(UTC).replace(microsecond=0)
    root_key, root_spki = _soft_signer()

    def sign(tbs: bytes) -> bytes:
        return root_key.sign(tbs, ec.ECDSA(hashes.SHA384()))

    root = issue_certificate(
        subject=x509.Name.build({"common_name": "Root"}),
        spki_der=root_spki,
        issuer=None,
        not_before=now,
        not_after=now + timedelta(days=1),
        ca=True,
        path_len=1,
        key_usage={"key_cert_sign", "crl_sign"},
        crl_url=None,
        sign=sign,
    )
    _leaf_key, leaf_spki = _soft_signer()
    leaf = issue_certificate(
        subject=x509.Name.build({"common_name": "Leaf"}),
        spki_der=leaf_spki,
        issuer=root,
        not_before=now,
        not_after=now + timedelta(days=1),
        ca=False,
        path_len=None,
        key_usage={"digital_signature", "non_repudiation"},
        crl_url="https://example.test/crl",
        sign=sign,
    )
    pub = load_der_public_key(root_spki)
    assert isinstance(pub, ec.EllipticCurvePublicKey)
    for cert in (root, leaf):
        pub.verify(
            cert["signature_value"].native,
            cert["tbs_certificate"].dump(),
            ec.ECDSA(hashes.SHA384()),
        )
    assert leaf.issuer == root.subject
    assert leaf.authority_key_identifier == root.key_identifier
    assert leaf.crl_distribution_points[0]["distribution_point"].native == [
        "https://example.test/crl"
    ]
    assert not leaf.ca and root.ca

    crl = build_crl(
        issuer=root,
        revoked=[RevokedEntry(serial=leaf.serial_number, revoked_at=now, reason="key_compromise")],
        crl_number=1,
        this_update=now,
        sign=sign,
    )
    parsed = asn1_crl.CertificateList.load(crl.dump())
    entries = parsed["tbs_cert_list"]["revoked_certificates"]
    assert entries[0]["user_certificate"].native == leaf.serial_number
    pub.verify(
        parsed["signature"].native, parsed["tbs_cert_list"].dump(), ec.ECDSA(hashes.SHA384())
    )


class TestModels:
    def test_field_name_must_match_order(self) -> None:
        with pytest.raises(ValidationError):
            FieldPlacement(name="sig_2", order=1, page=1, x=0, y=0, width=100, height=100)

    def test_orders_must_be_contiguous(self) -> None:
        f1 = FieldPlacement(name="sig_1", order=1, page=1, x=0, y=0, width=100, height=100)
        f3 = FieldPlacement(name="sig_3", order=3, page=1, x=200, y=0, width=100, height=100)
        with pytest.raises(ValidationError):
            PrepareMeta(input_sha256="0" * 64, fields=[f1, f3])

    def test_certify_only_first(self) -> None:
        with pytest.raises(ValidationError):
            SignMeta.model_validate(
                {
                    "input_sha256": "0" * 64,
                    "field_name": "sig_2",
                    "key_id": new_key_id(),
                    "totp_code": "123456",
                    "verify_url": "https://x.test/v/1",
                    "certify": True,
                    "appearance": {"name": "A", "position": "B"},
                }
            )


def test_stamp_time_is_shown_in_local_zone() -> None:
    from signer.pdf_ops import _local_time_label

    utc = datetime(2026, 11, 21, 3, 57, 31, tzinfo=UTC)
    assert _local_time_label(utc, 7, "WIB") == "21-11-2026 10:57:31 WIB"
    assert _local_time_label(utc, 9, "WIT") == "21-11-2026 12:57:31 WIT"
