"""Internal CA: certificate and CRL construction (asn1crypto) with pluggable signing.

All issuers (root, intermediate) use ECDSA P-384 with SHA-384. The `sign` callback receives
the DER-encoded TBS structure and returns a DER-encoded ECDSA signature, so the same code
works for the offline/software root key and for the intermediate key inside the HSM.
"""

from __future__ import annotations

import hashlib
import secrets
from collections.abc import Callable, Iterable
from dataclasses import dataclass
from datetime import UTC, datetime, timedelta

from asn1crypto import crl as asn1_crl
from asn1crypto import keys, pem, x509

from signer.errors import ProblemError
from signer.keystore import KeyStore
from signer.store import Store

SignFn = Callable[[bytes], bytes]
ISSUER_SIG_ALG = "sha384_ecdsa"
INTERMEDIATE_LABEL = "intermediate-ca"
AUDIT_LABEL = "audit"
CRL_VALIDITY = timedelta(days=7)
CRL_REFRESH = timedelta(hours=24)


def _time(dt: datetime) -> x509.Time:
    return x509.Time({"utc_time": dt} if dt.year < 2050 else {"general_time": dt})


def new_serial() -> int:
    """Random positive 127-bit serial number."""
    return int.from_bytes(secrets.token_bytes(16), "big") >> 1


def serial_hex(cert: x509.Certificate) -> str:
    return format(cert.serial_number, "x")


def to_pem(cert: x509.Certificate) -> str:
    armored: bytes = pem.armor("CERTIFICATE", cert.dump())
    return armored.decode()


def fingerprint(cert: x509.Certificate) -> str:
    return hashlib.sha256(cert.dump()).hexdigest()


def issue_certificate(
    *,
    subject: x509.Name,
    spki_der: bytes,
    issuer: x509.Certificate | None,
    not_before: datetime,
    not_after: datetime,
    ca: bool,
    path_len: int | None,
    key_usage: set[str],
    crl_url: str | None,
    sign: SignFn,
    serial: int | None = None,
) -> x509.Certificate:
    """Build and sign an X.509 v3 certificate. `issuer=None` produces a self-signed root."""
    spki = keys.PublicKeyInfo.load(spki_der)
    own_key_id: bytes = spki.sha1
    issuer_name = subject if issuer is None else issuer.subject
    issuer_key_id = own_key_id if issuer is None else issuer.key_identifier

    basic: dict[str, object] = {"ca": ca}
    if ca and path_len is not None:
        basic["path_len_constraint"] = path_len
    extensions: list[dict[str, object]] = [
        {"extn_id": "basic_constraints", "critical": True, "extn_value": basic},
        {"extn_id": "key_usage", "critical": True, "extn_value": key_usage},
        {"extn_id": "key_identifier", "critical": False, "extn_value": own_key_id},
        {
            "extn_id": "authority_key_identifier",
            "critical": False,
            "extn_value": {"key_identifier": issuer_key_id},
        },
    ]
    if crl_url:
        extensions.append(
            {
                "extn_id": "crl_distribution_points",
                "critical": False,
                "extn_value": [
                    {
                        "distribution_point": x509.DistributionPointName(
                            name="full_name",
                            value=[
                                x509.GeneralName(name="uniform_resource_identifier", value=crl_url)
                            ],
                        )
                    }
                ],
            }
        )

    tbs = x509.TbsCertificate(
        {
            "version": "v3",
            "serial_number": serial if serial is not None else new_serial(),
            "signature": {"algorithm": ISSUER_SIG_ALG},
            "issuer": issuer_name,
            "validity": {"not_before": _time(not_before), "not_after": _time(not_after)},
            "subject": subject,
            "subject_public_key_info": spki,
            "extensions": extensions,
        }
    )
    return x509.Certificate(
        {
            "tbs_certificate": tbs,
            "signature_algorithm": {"algorithm": ISSUER_SIG_ALG},
            "signature_value": sign(tbs.dump()),
        }
    )


@dataclass(frozen=True)
class RevokedEntry:
    serial: int
    revoked_at: datetime
    reason: str


def build_crl(
    *,
    issuer: x509.Certificate,
    revoked: Iterable[RevokedEntry],
    crl_number: int,
    this_update: datetime,
    sign: SignFn,
) -> asn1_crl.CertificateList:
    entries = [
        {
            "user_certificate": entry.serial,
            "revocation_date": _time(entry.revoked_at),
            "crl_entry_extensions": [
                {"extn_id": "crl_reason", "critical": False, "extn_value": entry.reason}
            ],
        }
        for entry in revoked
    ]
    tbs_fields: dict[str, object] = {
        "version": "v2",
        "signature": {"algorithm": ISSUER_SIG_ALG},
        "issuer": issuer.subject,
        "this_update": _time(this_update),
        "next_update": _time(this_update + CRL_VALIDITY),
        "crl_extensions": [
            {"extn_id": "crl_number", "critical": False, "extn_value": crl_number},
            {
                "extn_id": "authority_key_identifier",
                "critical": False,
                "extn_value": {"key_identifier": issuer.key_identifier},
            },
        ],
    }
    if entries:
        tbs_fields["revoked_certificates"] = entries
    tbs = asn1_crl.TbsCertList(tbs_fields)
    return asn1_crl.CertificateList(
        {
            "tbs_cert_list": tbs,
            "signature_algorithm": {"algorithm": ISSUER_SIG_ALG},
            "signature": sign(tbs.dump()),
        }
    )


class CertificateAuthority:
    """The online intermediate CA. Its key lives in the key store under INTERMEDIATE_LABEL."""

    def __init__(
        self, keystore: KeyStore, store: Store, *, org_name: str, country: str, crl_url: str
    ) -> None:
        self._keys = keystore
        self._store = store
        self._org_name = org_name
        self._country = country
        self._crl_url = crl_url

    def _cert(self, name: str) -> x509.Certificate:
        der = self._store.get_system(name)
        if der is None:
            raise ProblemError(
                503, "ca_not_initialized", "CA belum diinisialisasi", f"{name} tidak ditemukan"
            )
        return x509.Certificate.load(der)

    @property
    def root(self) -> x509.Certificate:
        return self._cert("root_cert")

    @property
    def intermediate(self) -> x509.Certificate:
        return self._cert("intermediate_cert")

    @property
    def audit_cert(self) -> x509.Certificate:
        return self._cert("audit_cert")

    def chain(self) -> list[x509.Certificate]:
        return [self.intermediate, self.root]

    def chain_pem(self) -> str:
        return "".join(to_pem(c) for c in self.chain())

    def _sign_with_intermediate(self, tbs: bytes) -> bytes:
        return self._keys.sign_digest(INTERMEDIATE_LABEL, hashlib.sha384(tbs).digest())

    def issue_end_entity(
        self, *, spki_der: bytes, subject: dict[str, str], valid_days: int
    ) -> x509.Certificate:
        now = datetime.now(UTC).replace(microsecond=0)
        attrs = {"country_name": self._country, "organization_name": self._org_name, **subject}
        return issue_certificate(
            subject=x509.Name.build(attrs),
            spki_der=spki_der,
            issuer=self.intermediate,
            not_before=now - timedelta(minutes=5),
            not_after=now + timedelta(days=valid_days),
            ca=False,
            path_len=None,
            key_usage={"digital_signature", "non_repudiation"},
            crl_url=self._crl_url,
            sign=self._sign_with_intermediate,
        )

    def publish_crl(self) -> bytes:
        number_raw = self._store.get_system("crl_number")
        number = int(number_raw.decode()) + 1 if number_raw else 1
        revoked = [
            RevokedEntry(
                serial=int(rec.serial_hex, 16),
                revoked_at=rec.revoked_at,
                reason=rec.revocation_reason or "unspecified",
            )
            for rec in self._store.list_revoked()
            if rec.revoked_at is not None
        ]
        crl = build_crl(
            issuer=self.intermediate,
            revoked=revoked,
            crl_number=number,
            this_update=datetime.now(UTC).replace(microsecond=0),
            sign=self._sign_with_intermediate,
        )
        der: bytes = crl.dump()
        self._store.set_system("crl", der)
        self._store.set_system("crl_number", str(number).encode())
        return der

    def current_crl(self) -> asn1_crl.CertificateList:
        """Return the latest CRL, re-issuing it when older than CRL_REFRESH."""
        der = self._store.get_system("crl")
        if der is not None:
            crl = asn1_crl.CertificateList.load(der)
            this_update: datetime = crl["tbs_cert_list"]["this_update"].native
            if datetime.now(UTC) - this_update < CRL_REFRESH:
                return crl
        return asn1_crl.CertificateList.load(self.publish_crl())
