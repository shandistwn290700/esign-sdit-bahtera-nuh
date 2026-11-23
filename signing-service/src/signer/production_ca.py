"""Production CA setup with an offline root (docs/runbooks/root-ca-offline.md).

Flow:
1. `tte-signer ca-csr`: the intermediate key is generated inside the HSM and a PKCS#10 CSR is
   written out. Running it again re-emits a CSR for the same pending key.
2. The CSR is carried to the offline root machine and signed there (openssl).
3. `tte-signer ca-import`: root + intermediate certificates are checked strictly and stored, then
   the audit-log key/certificate and the first CRL are created.

The root private key never exists on the server.
"""

from __future__ import annotations

import hashlib
from datetime import UTC, datetime

from asn1crypto import csr as asn1_csr
from asn1crypto import keys as asn1_keys
from asn1crypto import pem as asn1_pem
from asn1crypto import x509 as asn1_x509
from cryptography import x509
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives import hashes, serialization

from signer.pki import AUDIT_LABEL, INTERMEDIATE_LABEL
from signer.services import Services

PENDING_SPKI = "pending_intermediate_spki"


class CaSetupError(Exception):
    """A refused CA setup step; the message is shown to the operator as is."""


def create_intermediate_csr(svc: Services) -> str:
    """Return a PEM CSR for the intermediate CA key held in the HSM."""
    if svc.store.get_system("root_cert") is not None:
        raise CaSetupError("CA sudah terpasang. CSR baru tidak diperlukan.")

    spki_der = svc.store.get_system(PENDING_SPKI)
    if spki_der is None:
        spki_der = svc.keys.generate_ec_key(INTERMEDIATE_LABEL, "secp384r1")
        svc.store.set_system(PENDING_SPKI, spki_der)

    org, country = svc.settings.org_name, svc.settings.country
    info = asn1_csr.CertificationRequestInfo(
        {
            "version": "v1",
            "subject": asn1_x509.Name.build(
                {
                    "country_name": country,
                    "organization_name": org,
                    "common_name": f"{org} Signing CA",
                }
            ),
            "subject_pk_info": asn1_keys.PublicKeyInfo.load(spki_der),
            "attributes": [],
        }
    )
    signature = svc.keys.sign_digest(INTERMEDIATE_LABEL, hashlib.sha384(info.dump()).digest())
    request = asn1_csr.CertificationRequest(
        {
            "certification_request_info": info,
            "signature_algorithm": {"algorithm": "sha384_ecdsa"},
            "signature": signature,
        }
    )
    pem: bytes = asn1_pem.armor("CERTIFICATE REQUEST", request.dump())
    return pem.decode()


def import_ca_certificates(
    svc: Services, root_pem: bytes, intermediate_pem: bytes
) -> dict[str, str]:
    """Verify and install the offline root and the signed intermediate certificate.

    Returns the SHA-256 fingerprints so the operator can compare them with the offline record.
    """
    if svc.store.get_system("root_cert") is not None:
        raise CaSetupError("CA sudah terpasang. Impor ulang ditolak.")
    pending = svc.store.get_system(PENDING_SPKI)
    if pending is None:
        raise CaSetupError("Belum ada kunci CA perantara. Jalankan `tte-signer ca-csr` dahulu.")

    try:
        root = x509.load_pem_x509_certificate(root_pem)
        inter = x509.load_pem_x509_certificate(intermediate_pem)
    except ValueError as exc:
        raise CaSetupError(f"Berkas sertifikat tidak valid: {exc}") from exc

    now = datetime.now(UTC)
    _require_ca(root, "Root", now)
    _require_ca(inter, "CA perantara", now)
    try:
        root.verify_directly_issued_by(root)
    except (ValueError, TypeError, InvalidSignature) as exc:
        raise CaSetupError("Root bukan sertifikat self-signed yang valid.") from exc
    try:
        inter.verify_directly_issued_by(root)
    except (ValueError, TypeError, InvalidSignature) as exc:
        raise CaSetupError("Sertifikat CA perantara tidak ditandatangani oleh Root ini.") from exc

    inter_spki = inter.public_key().public_bytes(
        serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo
    )
    if inter_spki != pending:
        raise CaSetupError(
            "Kunci di sertifikat CA perantara tidak cocok dengan kunci di HSM. "
            "Pastikan yang ditandatangani adalah CSR terbaru dari server ini."
        )
    path_len = inter.extensions.get_extension_for_class(x509.BasicConstraints).value.path_length
    if path_len != 0:
        raise CaSetupError("CA perantara harus memakai pathlen:0.")
    if inter.not_valid_after_utc > root.not_valid_after_utc:
        raise CaSetupError("Masa berlaku CA perantara melebihi masa berlaku Root.")

    svc.store.set_system("root_cert", root.public_bytes(serialization.Encoding.DER))
    svc.store.set_system("intermediate_cert", inter.public_bytes(serialization.Encoding.DER))

    audit = svc.ca.issue_end_entity(
        spki_der=svc.keys.generate_ec_key(AUDIT_LABEL, "secp256r1"),
        subject={"common_name": f"{svc.settings.org_name} Audit Log Signer"},
        valid_days=svc.settings.signer_cert_days,
    )
    svc.store.set_system("audit_cert", audit.dump())
    svc.ca.publish_crl()

    return {
        "root_sha256": root.fingerprint(hashes.SHA256()).hex(":").upper(),
        "intermediate_sha256": inter.fingerprint(hashes.SHA256()).hex(":").upper(),
    }


def _require_ca(cert: x509.Certificate, name: str, now: datetime) -> None:
    try:
        bc = cert.extensions.get_extension_for_class(x509.BasicConstraints).value
        ku = cert.extensions.get_extension_for_class(x509.KeyUsage).value
    except x509.ExtensionNotFound as exc:
        raise CaSetupError(f"{name}: ekstensi basicConstraints/keyUsage wajib ada.") from exc
    if not bc.ca or not ku.key_cert_sign or not ku.crl_sign:
        raise CaSetupError(f"{name}: harus CA:TRUE dengan keyCertSign dan cRLSign.")
    if not cert.not_valid_before_utc <= now <= cert.not_valid_after_utc:
        raise CaSetupError(f"{name}: di luar masa berlaku.")
