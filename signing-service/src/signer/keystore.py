"""Key storage backends.

`Pkcs11KeyStore` talks to SoftHSM2 (or any PKCS#11 token). Private keys are generated on the
token as non-extractable and never leave it. `SoftKeyStore` keeps keys in memory and exists
only for tests.
"""

from __future__ import annotations

import threading
from collections.abc import Iterator
from contextlib import contextmanager
from pathlib import Path
from typing import Protocol

from asn1crypto import keys as asn1_keys
from asn1crypto import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.hazmat.primitives.asymmetric.utils import Prehashed
from pyhanko.sign import signers
from pyhanko_certvalidator.registry import SimpleCertificateStore

CURVES = {"secp256r1", "secp384r1"}


class KeyStoreError(Exception):
    """The key backend is unavailable or refused an operation."""


class KeyStore(Protocol):
    """Operations the service needs from a key backend."""

    lock: threading.RLock

    def generate_ec_key(self, label: str, curve: str) -> bytes:
        """Create a key pair and return the DER SubjectPublicKeyInfo."""
        ...

    def sign_digest(self, label: str, digest: bytes) -> bytes:
        """Sign a precomputed digest; return a DER-encoded ECDSA signature."""
        ...

    def cms_signer(
        self, label: str, cert: x509.Certificate, chain: list[x509.Certificate]
    ) -> signers.Signer:
        """Return a pyHanko signer bound to the key `label`."""
        ...

    def ping(self) -> bool: ...

    def close(self) -> None: ...


class Pkcs11KeyStore:
    """PKCS#11 backend. One session, serialised by `lock` (the session is not thread-safe)."""

    def __init__(self, lib_path: Path, token_label: str, pin: str) -> None:
        import pkcs11

        self._pkcs11 = pkcs11
        self._lib = pkcs11.lib(str(lib_path))
        self._token = self._lib.get_token(token_label=token_label)
        self._session = self._token.open(rw=True, user_pin=pin)
        self.lock = threading.RLock()

    @contextmanager
    def _wrap(self) -> Iterator[None]:
        try:
            yield
        except self._pkcs11.PKCS11Error as exc:
            raise KeyStoreError(type(exc).__name__) from exc

    def generate_ec_key(self, label: str, curve: str) -> bytes:
        if curve not in CURVES:
            raise ValueError(f"unsupported curve {curve}")
        from pkcs11 import Attribute, KeyType
        from pkcs11.util.ec import encode_ec_public_key, encode_named_curve_parameters

        with self.lock, self._wrap():
            params = self._session.create_domain_parameters(
                KeyType.EC, {Attribute.EC_PARAMS: encode_named_curve_parameters(curve)}, local=True
            )
            public, _private = params.generate_keypair(
                store=True,
                label=label,
                id=label.encode(),
                private_template={
                    Attribute.EXTRACTABLE: False,
                    Attribute.SENSITIVE: True,
                    Attribute.SIGN: True,
                },
            )
            der: bytes = encode_ec_public_key(public)
            return der

    def sign_digest(self, label: str, digest: bytes) -> bytes:
        from pkcs11 import Mechanism, ObjectClass
        from pkcs11.util.ec import encode_ecdsa_signature

        with self.lock, self._wrap():
            key = self._session.get_key(object_class=ObjectClass.PRIVATE_KEY, label=label)
            raw = key.sign(digest, mechanism=Mechanism.ECDSA)
            der: bytes = encode_ecdsa_signature(raw)
            return der

    def cms_signer(
        self, label: str, cert: x509.Certificate, chain: list[x509.Certificate]
    ) -> signers.Signer:
        from pyhanko.sign.pkcs11 import PKCS11Signer

        return PKCS11Signer(
            self._session,
            key_label=label,
            signing_cert=cert,
            ca_chain=chain,
            other_certs_to_pull=(),
            use_raw_mechanism=True,  # SoftHSM2 only implements raw CKM_ECDSA
        )

    def ping(self) -> bool:
        try:
            with self.lock:
                self._session.generate_random(32)
        except self._pkcs11.PKCS11Error:
            return False
        return True

    def close(self) -> None:
        with self.lock:
            self._session.close()


class SoftKeyStore:
    """In-memory backend for tests. Never use in production."""

    def __init__(self) -> None:
        self.lock = threading.RLock()
        self._keys: dict[str, ec.EllipticCurvePrivateKey] = {}

    def generate_ec_key(self, label: str, curve: str) -> bytes:
        if curve not in CURVES:
            raise ValueError(f"unsupported curve {curve}")
        key = ec.generate_private_key(ec.SECP256R1() if curve == "secp256r1" else ec.SECP384R1())
        self._keys[label] = key
        return key.public_key().public_bytes(
            serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo
        )

    def sign_digest(self, label: str, digest: bytes) -> bytes:
        algorithm: hashes.HashAlgorithm = hashes.SHA384() if len(digest) == 48 else hashes.SHA256()
        return self._keys[label].sign(digest, ec.ECDSA(Prehashed(algorithm)))

    def cms_signer(
        self, label: str, cert: x509.Certificate, chain: list[x509.Certificate]
    ) -> signers.Signer:
        pkcs8 = self._keys[label].private_bytes(
            serialization.Encoding.DER,
            serialization.PrivateFormat.PKCS8,
            serialization.NoEncryption(),
        )
        return signers.SimpleSigner(
            signing_cert=cert,
            signing_key=asn1_keys.PrivateKeyInfo.load(pkcs8),
            cert_registry=SimpleCertificateStore.from_certs(chain),
        )

    def ping(self) -> bool:
        return True

    def close(self) -> None:
        self._keys.clear()
