"""Production CA setup: CSR from the HSM key, offline root signs it, strict import."""

from __future__ import annotations

import base64
import os
from collections.abc import Iterator
from datetime import UTC, datetime, timedelta
from pathlib import Path

import pytest
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.x509.oid import NameOID

from signer.config import Settings
from signer.keystore import SoftKeyStore
from signer.pki import CertificateAuthority
from signer.production_ca import CaSetupError, create_intermediate_csr, import_ca_certificates
from signer.services import Services
from signer.store import Store
from signer.totp import SecretBox


@pytest.fixture
def svc(tmp_path: Path) -> Iterator[Services]:
    """Services with NO CA yet (unlike the shared `env` fixture, which runs init_dev_ca)."""
    settings = Settings(
        app_env="test",
        api_tokens="t" * 40,
        data_dir=tmp_path,
        hsm_pin="unused",
        totp_encryption_key=base64.b64encode(os.urandom(32)).decode(),
        tsa_url=None,
        require_timestamp=False,
        crl_public_url="https://verifikasi.example.sch.id/pki/crl",
        org_name="SDIT Contoh",
    )
    store = Store(settings.db_path)
    keys = SoftKeyStore()
    ca = CertificateAuthority(
        keys, store, org_name=settings.org_name, country="ID", crl_url=settings.crl_public_url
    )
    services = Services(
        settings=settings, store=store, keys=keys, ca=ca, box=SecretBox(settings.totp_key())
    )
    yield services
    services.close()


class OfflineRoot:
    """What the operator does with openssl on the offline machine."""

    def __init__(self, cn: str = "SDIT Contoh Root CA", years: int = 15) -> None:
        self.key = ec.generate_private_key(ec.SECP384R1())
        name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, cn)])
        now = datetime.now(UTC)
        self.cert = (
            x509.CertificateBuilder()
            .subject_name(name)
            .issuer_name(name)
            .public_key(self.key.public_key())
            .serial_number(x509.random_serial_number())
            .not_valid_before(now - timedelta(minutes=5))
            .not_valid_after(now + timedelta(days=365 * years))
            .add_extension(x509.BasicConstraints(ca=True, path_length=1), critical=True)
            .add_extension(_ca_key_usage(), critical=True)
            .sign(self.key, hashes.SHA384())
        )

    def sign_csr(self, csr_pem: str, *, path_length: int | None = 0, days: int = 1825) -> bytes:
        csr = x509.load_pem_x509_csr(csr_pem.encode())
        assert csr.is_signature_valid
        now = datetime.now(UTC)
        cert = (
            x509.CertificateBuilder()
            .subject_name(csr.subject)
            .issuer_name(self.cert.subject)
            .public_key(csr.public_key())
            .serial_number(x509.random_serial_number())
            .not_valid_before(now - timedelta(minutes=5))
            .not_valid_after(now + timedelta(days=days))
            .add_extension(x509.BasicConstraints(ca=True, path_length=path_length), critical=True)
            .add_extension(_ca_key_usage(), critical=True)
            .sign(self.key, hashes.SHA384())
        )
        return cert.public_bytes(serialization.Encoding.PEM)

    @property
    def pem(self) -> bytes:
        return self.cert.public_bytes(serialization.Encoding.PEM)


def _ca_key_usage() -> x509.KeyUsage:
    return x509.KeyUsage(
        digital_signature=False,
        content_commitment=False,
        key_encipherment=False,
        data_encipherment=False,
        key_agreement=False,
        key_cert_sign=True,
        crl_sign=True,
        encipher_only=False,
        decipher_only=False,
    )


def test_csr_is_valid_and_stable_until_import(svc: Services) -> None:
    first = create_intermediate_csr(svc)
    csr = x509.load_pem_x509_csr(first.encode())
    assert csr.is_signature_valid
    assert "SDIT Contoh Signing CA" in csr.subject.rfc4514_string()
    # Re-running reuses the pending HSM key instead of orphaning it.
    second = x509.load_pem_x509_csr(create_intermediate_csr(svc).encode())
    assert second.public_key() == csr.public_key()


def test_import_installs_chain_audit_cert_and_crl(svc: Services) -> None:
    root = OfflineRoot()
    inter_pem = root.sign_csr(create_intermediate_csr(svc))

    prints = import_ca_certificates(svc, root.pem, inter_pem)

    assert prints["root_sha256"] == root.cert.fingerprint(hashes.SHA256()).hex(":").upper()
    assert svc.ca.root.subject.native["common_name"] == "SDIT Contoh Root CA"
    assert svc.ca.intermediate.subject.native["common_name"] == "SDIT Contoh Signing CA"
    assert svc.store.get_system("audit_cert") is not None
    assert svc.store.get_system("crl") is not None
    # The online CA can now issue signer certificates chaining to the offline root.
    leaf = svc.ca.issue_end_entity(
        spki_der=svc.keys.generate_ec_key("sk_test", "secp256r1"),
        subject={"common_name": "Budi"},
        valid_days=30,
    )
    issued = x509.load_der_x509_certificate(leaf.dump())
    intermediate = x509.load_der_x509_certificate(svc.ca.intermediate.dump())
    issued.verify_directly_issued_by(intermediate)
    with pytest.raises(CaSetupError, match="sudah terpasang"):
        create_intermediate_csr(svc)


def test_import_rejects_certificate_for_another_key(svc: Services) -> None:
    create_intermediate_csr(svc)
    root = OfflineRoot()
    other = ec.generate_private_key(ec.SECP384R1())
    foreign_csr = (
        x509.CertificateSigningRequestBuilder()
        .subject_name(x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "Asing")]))
        .sign(other, hashes.SHA384())
        .public_bytes(serialization.Encoding.PEM)
        .decode()
    )
    with pytest.raises(CaSetupError, match="tidak cocok dengan kunci di HSM"):
        import_ca_certificates(svc, root.pem, root.sign_csr(foreign_csr))
    assert svc.store.get_system("root_cert") is None


def test_import_rejects_intermediate_from_a_different_root(svc: Services) -> None:
    signed_by_other = OfflineRoot("Root Lain").sign_csr(create_intermediate_csr(svc))
    with pytest.raises(CaSetupError, match="tidak ditandatangani oleh Root"):
        import_ca_certificates(svc, OfflineRoot().pem, signed_by_other)


def test_import_rejects_wrong_constraints_and_lifetime(svc: Services) -> None:
    csr = create_intermediate_csr(svc)
    root = OfflineRoot(years=2)
    with pytest.raises(CaSetupError, match="pathlen:0"):
        import_ca_certificates(svc, root.pem, root.sign_csr(csr, path_length=None))
    with pytest.raises(CaSetupError, match="melebihi masa berlaku Root"):
        import_ca_certificates(svc, root.pem, root.sign_csr(csr, days=365 * 5))


def test_import_requires_a_pending_key(svc: Services) -> None:
    root = OfflineRoot()
    with pytest.raises(CaSetupError, match="ca-csr"):
        import_ca_certificates(svc, root.pem, root.pem)
