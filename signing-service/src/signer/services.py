"""Business logic, independent of HTTP. Routes in app.py stay thin."""

from __future__ import annotations

import asyncio
import base64
import json
import threading
import time
from collections.abc import Callable
from dataclasses import dataclass, field
from datetime import UTC, datetime

from asn1crypto import x509
from pyhanko.sign import timestamps
from pyhanko.sign.general import SigningError

from signer import pdf_ops, totp
from signer.config import Settings
from signer.errors import ProblemError
from signer.ids import new_key_id
from signer.keystore import KeyStore, KeyStoreError, Pkcs11KeyStore
from signer.models import (
    AuditAnchorRequest,
    AuditAnchorResponse,
    CertificateInfo,
    CreateSignerRequest,
    CreateSignerResponse,
    PrepareMeta,
    ResetTotpRequest,
    RevokeRequest,
    SignerOut,
    SignMeta,
    TotpEnrollment,
    ValidationReport,
)
from signer.pki import AUDIT_LABEL, CertificateAuthority, fingerprint, serial_hex, to_pem
from signer.store import SignerRecord, Store
from signer.totp import LOCKOUT_SECONDS, MAX_FAILURES, SecretBox, TotpResult


@dataclass
class Services:
    settings: Settings
    store: Store
    keys: KeyStore
    ca: CertificateAuthority
    box: SecretBox
    clock: Callable[[], float] = time.time
    totp_lock: threading.Lock = field(default_factory=threading.Lock)

    def close(self) -> None:
        self.keys.close()
        self.store.close()


def build_services(settings: Settings) -> Services:
    settings.token_list()  # fail fast on bad configuration
    settings.data_dir.mkdir(parents=True, exist_ok=True)
    store = Store(settings.db_path)
    keys = Pkcs11KeyStore(
        settings.pkcs11_lib, settings.hsm_token_label, settings.hsm_pin.get_secret_value()
    )
    ca = CertificateAuthority(
        keys,
        store,
        org_name=settings.org_name,
        country=settings.country,
        crl_url=settings.crl_public_url,
    )
    return Services(
        settings=settings, store=store, keys=keys, ca=ca, box=SecretBox(settings.totp_key())
    )


# --- signers -----------------------------------------------------------------------------


def _not_found() -> ProblemError:
    return ProblemError(404, "signer_not_found", "Penanda tangan tidak ditemukan")


def _require(svc: Services, key_id: str) -> SignerRecord:
    rec = svc.store.get_signer(key_id)
    if rec is None:
        raise _not_found()
    return rec


def _status(rec: SignerRecord, cert: x509.Certificate) -> str:
    if rec.revoked_at is not None:
        return "revoked"
    not_after: datetime = cert["tbs_certificate"]["validity"]["not_after"].native
    return "expired" if not_after <= datetime.now(UTC) else "active"


def signer_out(rec: SignerRecord) -> SignerOut:
    cert = x509.Certificate.load(rec.cert_der)
    validity = cert["tbs_certificate"]["validity"]
    return SignerOut(
        key_id=rec.key_id,
        signer_ref=rec.signer_ref,  # type: ignore[arg-type]
        status=_status(rec, cert),  # type: ignore[arg-type]
        totp_status=rec.totp_status,  # type: ignore[arg-type]
        revoked_at=rec.revoked_at,
        revocation_reason=rec.revocation_reason,
        certificate=CertificateInfo(
            serial=serial_hex(cert),
            subject=cert.subject.human_friendly,
            not_before=validity["not_before"].native,
            not_after=validity["not_after"].native,
            pem=to_pem(cert),
            sha256_fingerprint=fingerprint(cert),
        ),
    )


def _hsm_unavailable(exc: Exception) -> ProblemError:
    return ProblemError(
        503, "hsm_unavailable", "Modul kunci tidak tersedia", str(exc) or type(exc).__name__
    )


def create_signer(
    svc: Services, req: CreateSignerRequest, request_id: str | None
) -> CreateSignerResponse:
    if svc.store.find_unrevoked_by_ref(str(req.signer_ref)) is not None:
        raise ProblemError(409, "signer_exists", "Pengguna ini sudah memiliki kunci aktif")
    key_id = new_key_id()
    subject = {"common_name": req.full_name, "title": req.position, "email_address": req.email}
    if req.nip:
        subject["serial_number"] = req.nip
    if req.organization_unit:
        subject["organizational_unit_name"] = req.organization_unit
    try:
        spki = svc.keys.generate_ec_key(key_id, "secp256r1")
        cert = svc.ca.issue_end_entity(
            spki_der=spki, subject=subject, valid_days=svc.settings.signer_cert_days
        )
    except KeyStoreError as exc:
        raise _hsm_unavailable(exc) from exc
    secret = totp.new_secret()
    svc.store.insert_signer(
        key_id=key_id,
        signer_ref=str(req.signer_ref),
        cert_der=cert.dump(),
        serial_hex=serial_hex(cert),
        totp_secret_enc=svc.box.seal(secret.encode(), key_id.encode()),
    )
    svc.store.log_op(
        "create_signer",
        "ok",
        request_id=request_id,
        key_id=key_id,
        actor_ref=str(req.actor_ref) if req.actor_ref else None,
    )
    uri = totp.provisioning_uri(secret, req.full_name, f"{svc.settings.org_name} TTE")
    out = signer_out(_require(svc, key_id))
    return CreateSignerResponse(**out.model_dump(), totp_enrollment=TotpEnrollment(otpauth_uri=uri))


def verify_totp(svc: Services, key_id: str, code: str) -> None:
    """Check a TOTP code with replay protection and lockout. Raises ProblemError on failure."""
    with svc.totp_lock:
        rec = _require(svc, key_id)
        now = svc.clock()
        window_open = (
            rec.fail_window_start is not None and now - rec.fail_window_start < LOCKOUT_SECONDS
        )
        if window_open and rec.fail_count >= MAX_FAILURES:
            assert rec.fail_window_start is not None
            retry = int(LOCKOUT_SECONDS - (now - rec.fail_window_start)) + 1
            raise ProblemError(
                429, "totp_locked", "Terlalu banyak percobaan", headers={"Retry-After": str(retry)}
            )
        secret = svc.box.open(rec.totp_secret_enc, key_id.encode()).decode()
        result, step = totp.check_code(secret, code, now=now, last_used_step=rec.totp_last_step)
        if result is TotpResult.OK:
            svc.store.update_signer(
                key_id, totp_last_step=step, fail_count=0, fail_window_start=None
            )
            return
        if window_open:
            svc.store.update_signer(key_id, fail_count=rec.fail_count + 1)
        else:
            svc.store.update_signer(key_id, fail_count=1, fail_window_start=now)
    if result is TotpResult.REPLAYED:
        raise ProblemError(422, "totp_replayed", "Kode sudah dipakai")
    raise ProblemError(422, "totp_invalid", "Kode salah")


def confirm_totp(svc: Services, key_id: str, code: str, request_id: str | None) -> SignerOut:
    rec = _require(svc, key_id)
    if rec.revoked_at is not None:
        raise ProblemError(409, "signer_revoked", "Sertifikat sudah dicabut")
    if rec.totp_status == "active":
        raise ProblemError(409, "totp_already_active", "TOTP sudah aktif")
    verify_totp(svc, key_id, code)
    svc.store.update_signer(key_id, totp_status="active")
    svc.store.log_op("confirm_totp", "ok", request_id=request_id, key_id=key_id)
    return signer_out(_require(svc, key_id))


def reset_totp(
    svc: Services, key_id: str, req: ResetTotpRequest, request_id: str | None
) -> TotpEnrollment:
    rec = _require(svc, key_id)
    if rec.revoked_at is not None:
        raise ProblemError(409, "signer_revoked", "Sertifikat sudah dicabut")
    secret = totp.new_secret()
    svc.store.update_signer(
        key_id,
        totp_secret_enc=svc.box.seal(secret.encode(), key_id.encode()),
        totp_status="pending",
        totp_last_step=None,
        fail_count=0,
        fail_window_start=None,
    )
    svc.store.log_op(
        "reset_totp", "ok", request_id=request_id, key_id=key_id, actor_ref=str(req.actor_ref)
    )
    cert = x509.Certificate.load(rec.cert_der)
    name = cert.subject.native.get("common_name", key_id)
    return TotpEnrollment(
        otpauth_uri=totp.provisioning_uri(secret, name, f"{svc.settings.org_name} TTE")
    )


def revoke_signer(
    svc: Services, key_id: str, req: RevokeRequest, request_id: str | None
) -> SignerOut:
    rec = _require(svc, key_id)
    if rec.revoked_at is None:
        svc.store.update_signer(
            # Full precision: signing times are truncated to whole seconds, so truncating the
            # revocation time too would make a same-second signature look post-revocation.
            key_id,
            revoked_at=datetime.now(UTC),
            revocation_reason=req.reason,
        )
        try:
            svc.ca.publish_crl()
        except KeyStoreError as exc:
            raise _hsm_unavailable(exc) from exc
        svc.store.log_op(
            "revoke_signer",
            "ok",
            request_id=request_id,
            key_id=key_id,
            actor_ref=str(req.actor_ref),
        )
    return signer_out(_require(svc, key_id))


# --- documents ---------------------------------------------------------------------------


def _check_hash(data: bytes, expected: str) -> str:
    actual = pdf_ops.sha256_hex(data)
    if actual != expected:
        raise ProblemError(422, "input_hash_mismatch", "Hash PDF tidak cocok", f"diterima {actual}")
    return actual


@dataclass(frozen=True)
class PdfResult:
    pdf: bytes
    input_sha256: str
    output_sha256: str


@dataclass(frozen=True)
class SignResult(PdfResult):
    signed_at: datetime
    certificate_serial: str


def prepare_document(
    svc: Services, data: bytes, meta: PrepareMeta, request_id: str | None
) -> PdfResult:
    in_hash = _check_hash(data, meta.input_sha256)
    out = pdf_ops.prepare(data, meta.fields)
    out_hash = pdf_ops.sha256_hex(out)
    svc.store.log_op(
        "prepare", "ok", request_id=request_id, input_sha256=in_hash, output_sha256=out_hash
    )
    return PdfResult(pdf=out, input_sha256=in_hash, output_sha256=out_hash)


def sign_document(svc: Services, data: bytes, meta: SignMeta, request_id: str | None) -> SignResult:
    in_hash = _check_hash(data, meta.input_sha256)
    rec = _require(svc, meta.key_id)
    cert = x509.Certificate.load(rec.cert_der)
    status = _status(rec, cert)
    if status != "active":
        raise ProblemError(409, "signer_revoked", "Sertifikat tidak aktif", status)
    if rec.totp_status != "active":
        raise ProblemError(409, "signer_totp_pending", "TOTP tanda tangan belum diaktifkan")
    pdf_ops.check_signable(data, meta.field_name)
    actor = str(meta.actor_ref) if meta.actor_ref else None
    try:
        verify_totp(svc, meta.key_id, meta.totp_code)
    except ProblemError as exc:
        svc.store.log_op(
            "sign",
            exc.code,
            request_id=request_id,
            key_id=meta.key_id,
            actor_ref=actor,
            input_sha256=in_hash,
        )
        raise
    try:
        with svc.keys.lock:
            signer = svc.keys.cms_signer(meta.key_id, cert, svc.ca.chain())
            outcome = pdf_ops.sign(
                data,
                field_name=meta.field_name,
                signer=signer,
                certify=meta.certify,
                verify_url=meta.verify_url,
                appearance=meta.appearance,
                reason=meta.reason,
                location=meta.location,
                tsa_url=svc.settings.tsa_url,
                utc_offset_hours=svc.settings.stamp_utc_offset_hours,
                timezone_label=svc.settings.stamp_timezone_label,
            )
    except (KeyStoreError, SigningError) as exc:
        svc.store.log_op(
            "sign",
            "hsm_unavailable",
            request_id=request_id,
            key_id=meta.key_id,
            actor_ref=actor,
            input_sha256=in_hash,
        )
        raise _hsm_unavailable(exc) from exc
    except ProblemError as exc:
        svc.store.log_op(
            "sign",
            exc.code,
            request_id=request_id,
            key_id=meta.key_id,
            actor_ref=actor,
            input_sha256=in_hash,
        )
        raise
    out_hash = pdf_ops.sha256_hex(outcome.pdf)
    svc.store.log_op(
        "sign",
        "ok",
        request_id=request_id,
        key_id=meta.key_id,
        actor_ref=actor,
        input_sha256=in_hash,
        output_sha256=out_hash,
    )
    return SignResult(
        pdf=outcome.pdf,
        input_sha256=in_hash,
        output_sha256=out_hash,
        signed_at=outcome.signed_at,
        certificate_serial=serial_hex(cert),
    )


def validate_document(svc: Services, data: bytes) -> ValidationReport:
    def revoked_at(serial: str) -> datetime | None:
        rec = svc.store.get_signer_by_serial(serial)
        return rec.revoked_at if rec else None

    def key_id_for(serial: str) -> str | None:
        rec = svc.store.get_signer_by_serial(serial)
        return rec.key_id if rec else None

    try:
        crl_time: datetime | None = svc.ca.current_crl()["tbs_cert_list"]["this_update"].native
    except KeyStoreError:
        crl_time = None
    return pdf_ops.validate(
        data,
        root=svc.ca.root,
        intermediate=svc.ca.intermediate,
        revoked_at=revoked_at,
        key_id_for_serial=key_id_for,
        require_timestamp=svc.settings.require_timestamp,
        crl_this_update=crl_time,
    )


# --- system ------------------------------------------------------------------------------


def sign_audit_anchor(
    svc: Services, req: AuditAnchorRequest, request_id: str | None
) -> AuditAnchorResponse:
    payload = json.dumps(
        {
            "anchored_date": req.anchored_date.isoformat(),
            "chain_head_sha256": req.chain_head_sha256,
            "last_entry_id": req.last_entry_id,
        },
        sort_keys=True,
        separators=(",", ":"),
    ).encode()
    tsa = svc.settings.tsa_url
    timestamper = timestamps.HTTPTimeStamper(tsa, timeout=20) if tsa else None
    try:
        with svc.keys.lock:
            signer = svc.keys.cms_signer(AUDIT_LABEL, svc.ca.audit_cert, svc.ca.chain())
            cms = asyncio.run(
                signer.async_sign_general_data(
                    payload, "sha256", detached=True, timestamper=timestamper
                )
            )
    except (KeyStoreError, SigningError) as exc:
        raise _hsm_unavailable(exc) from exc
    except timestamps.TimestampRequestError as exc:
        raise ProblemError(503, "tsa_unavailable", "Timestamp authority tidak merespons") from exc
    svc.store.log_op(
        "audit_anchor", "ok", request_id=request_id, input_sha256=req.chain_head_sha256
    )
    return AuditAnchorResponse(
        cms_signature=base64.b64encode(cms.dump()).decode(),
        certificate_serial=serial_hex(svc.ca.audit_cert),
        signed_at=datetime.now(UTC).replace(microsecond=0),
    )


def health(svc: Services) -> tuple[bool, dict[str, str]]:
    checks = {"hsm": "ok" if svc.keys.ping() else "error", "tsa": "unknown"}
    crl_der = svc.store.get_system("crl")
    fresh = False
    if crl_der is not None:
        from asn1crypto import crl as asn1_crl

        next_update: datetime = asn1_crl.CertificateList.load(crl_der)["tbs_cert_list"][
            "next_update"
        ].native
        fresh = next_update > datetime.now(UTC)
    checks["crl_fresh"] = "ok" if fresh else "stale"
    return checks["hsm"] == "ok" and fresh, checks
