"""Development CA bootstrap.

Production uses an offline root (docs/runbooks/root-ca-offline.md): the root key never touches
the server. This module creates a throwaway software root for local development and tests.
"""

from __future__ import annotations

import os
from datetime import UTC, datetime, timedelta
from pathlib import Path

from asn1crypto import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec

from signer.pki import AUDIT_LABEL, INTERMEDIATE_LABEL, issue_certificate
from signer.services import Services

CA_KEY_USAGE = {"key_cert_sign", "crl_sign"}


def init_dev_ca(svc: Services, *, root_key_out: Path | None = None) -> bool:
    """Create root (software), intermediate + audit keys (key store) and the first CRL.

    Returns:
        False if a CA already exists, True if one was created.
    """
    if svc.store.get_system("root_cert") is not None:
        return False
    now = datetime.now(UTC).replace(microsecond=0)
    org, country = svc.settings.org_name, svc.settings.country
    root_key = ec.generate_private_key(ec.SECP384R1())

    def root_sign(tbs: bytes) -> bytes:
        return root_key.sign(tbs, ec.ECDSA(hashes.SHA384()))

    root = issue_certificate(
        subject=x509.Name.build(
            {
                "country_name": country,
                "organization_name": org,
                "common_name": f"{org} Root CA (DEV)",
            }
        ),
        spki_der=root_key.public_key().public_bytes(
            serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo
        ),
        issuer=None,
        not_before=now - timedelta(minutes=5),
        not_after=now + timedelta(days=3650),
        ca=True,
        path_len=1,
        key_usage=CA_KEY_USAGE,
        crl_url=None,
        sign=root_sign,
    )
    intermediate = issue_certificate(
        subject=x509.Name.build(
            {
                "country_name": country,
                "organization_name": org,
                "common_name": f"{org} Signing CA (DEV)",
            }
        ),
        spki_der=svc.keys.generate_ec_key(INTERMEDIATE_LABEL, "secp384r1"),
        issuer=root,
        not_before=now - timedelta(minutes=5),
        not_after=now + timedelta(days=1825),
        ca=True,
        path_len=0,
        key_usage=CA_KEY_USAGE,
        crl_url=None,
        sign=root_sign,
    )
    svc.store.set_system("root_cert", root.dump())
    svc.store.set_system("intermediate_cert", intermediate.dump())

    audit = svc.ca.issue_end_entity(
        spki_der=svc.keys.generate_ec_key(AUDIT_LABEL, "secp256r1"),
        subject={"common_name": f"{org} Audit Log Signer"},
        valid_days=svc.settings.signer_cert_days,
    )
    svc.store.set_system("audit_cert", audit.dump())
    svc.ca.publish_crl()

    if root_key_out is not None:
        pem_bytes = root_key.private_bytes(
            serialization.Encoding.PEM,
            serialization.PrivateFormat.PKCS8,
            serialization.NoEncryption(),
        )
        fd = os.open(root_key_out, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "wb") as fh:
            fh.write(pem_bytes)
    return True
