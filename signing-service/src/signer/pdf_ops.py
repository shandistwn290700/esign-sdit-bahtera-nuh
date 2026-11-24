"""PDF operations on top of pyHanko: prepare fields, sign one field, validate all signatures.

Signature level in v1 is PAdES B-T (signature + RFC 3161 timestamp, no DSS). Keeping the
signature as the last revision lets us detect any change appended after it by checking that
the latest signature covers the entire file.
"""

from __future__ import annotations

import hashlib
import re
from collections.abc import Callable, Sequence
from dataclasses import dataclass
from datetime import UTC, datetime, timedelta, timezone
from functools import lru_cache
from io import BytesIO
from typing import Any, Literal

import certifi
from asn1crypto import pem, x509
from pyhanko import stamp
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.misc import PdfError
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign import fields, signers, timestamps
from pyhanko.sign.validation import validate_pdf_signature
from pyhanko_certvalidator import ValidationContext

from signer.errors import ProblemError
from signer.models import Appearance, FieldPlacement, SignatureResult, ValidationReport

_PARSE_ERRORS = (PdfError, ValueError, KeyError, IndexError, TypeError)
_FIELD_RE = re.compile(r"^sig_(\d{1,2})$")
CertLevel = Literal["no_changes", "form_filling", "annotations"]
Coverage = Literal["entire_file", "entire_revision", "partial"]
_MDP_NAMES: dict[str, CertLevel] = {
    "NO_CHANGES": "no_changes",
    "FILL_FORMS": "form_filling",
    "ANNOTATE": "annotations",
}
_COVERAGE: dict[str, Coverage] = {
    "ENTIRE_FILE": "entire_file",
    "ENTIRE_REVISION": "entire_revision",
}


def sha256_hex(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def _open(data: bytes) -> PdfFileReader:
    try:
        reader = PdfFileReader(BytesIO(data), strict=False)
        encrypted = bool(reader.encrypted)
    except _PARSE_ERRORS as exc:
        raise ProblemError(422, "pdf_invalid", "PDF tidak dapat dibaca", str(exc)) from exc
    if encrypted:
        raise ProblemError(422, "pdf_encrypted", "PDF terenkripsi/berpassword tidak didukung")
    return reader


def _field_order(name: str) -> int:
    match = _FIELD_RE.match(name)
    if not match:
        raise ProblemError(400, "validation_failed", "Nama field tidak valid", name)
    return int(match.group(1))


def _field_states(reader: PdfFileReader) -> dict[str, bool]:
    """Map signature field name -> filled?"""
    return {name: value is not None for name, value, _ref in fields.enumerate_sig_fields(reader)}


def _media_box(reader: PdfFileReader, page_ix: int) -> tuple[float, float, float, float]:
    found = reader.find_page_for_modification(page_ix)
    node: Any = (found[0] if isinstance(found, tuple) else found).get_object()
    while node is not None:
        if "/MediaBox" in node:
            box = [float(v) for v in node["/MediaBox"]]
            return box[0], box[1], box[2], box[3]
        parent = node.get("/Parent")
        node = parent.get_object() if parent is not None else None
    return 0.0, 0.0, 612.0, 792.0


def _overlaps(a: FieldPlacement, b: FieldPlacement) -> bool:
    return (
        a.page == b.page
        and a.x < b.x + b.width
        and b.x < a.x + a.width
        and a.y < b.y + b.height
        and b.y < a.y + a.height
    )


def prepare(data: bytes, placements: Sequence[FieldPlacement]) -> bytes:
    """Append every (empty) signature field in one incremental update."""
    reader = _open(data)
    try:
        if reader.embedded_signatures:
            raise ProblemError(422, "pdf_already_signed", "PDF sudah mengandung tanda tangan")
        if set(_field_states(reader)) & {p.name for p in placements}:
            raise ProblemError(
                422, "pdf_already_signed", "PDF sudah memiliki field dengan nama sama"
            )
        page_count = int(reader.root["/Pages"]["/Count"])
        for p in placements:
            if p.page > page_count:
                raise ProblemError(
                    422, "page_not_found", "Halaman tidak ada", f"{p.name}: halaman {p.page}"
                )
            x0, y0, x1, y1 = _media_box(reader, p.page - 1)
            if p.x < x0 or p.y < y0 or p.x + p.width > x1 or p.y + p.height > y1:
                raise ProblemError(422, "field_out_of_bounds", "Kotak QR di luar halaman", p.name)
        for i, a in enumerate(placements):
            for b in placements[i + 1 :]:
                if _overlaps(a, b):
                    raise ProblemError(
                        422,
                        "field_overlap",
                        "Kotak QR saling tumpang tindih",
                        f"{a.name}, {b.name}",
                    )
        writer = IncrementalPdfFileWriter(BytesIO(data), strict=False)
        for p in sorted(placements, key=lambda f: f.order):
            box = (round(p.x), round(p.y), round(p.x + p.width), round(p.y + p.height))
            fields.append_signature_field(
                writer, fields.SigFieldSpec(sig_field_name=p.name, on_page=p.page - 1, box=box)
            )
        out = BytesIO()
        writer.write(out)
        return out.getvalue()
    except ProblemError:
        raise
    except _PARSE_ERRORS as exc:
        raise ProblemError(422, "pdf_invalid", "PDF tidak dapat diproses", str(exc)) from exc


def check_signable(data: bytes, field_name: str) -> None:
    """Verify the field exists, is empty, earlier fields are signed, and nothing was appended."""
    reader = _open(data)
    order = _field_order(field_name)
    try:
        states = _field_states(reader)
        if field_name not in states:
            raise ProblemError(409, "field_not_found", "Field tanda tangan tidak ditemukan")
        if states[field_name]:
            raise ProblemError(409, "field_already_signed", "Field sudah ditandatangani")
        for k in range(1, order):
            if not states.get(f"sig_{k}", False):
                raise ProblemError(
                    409, "previous_field_unsigned", "Penanda tangan sebelumnya belum selesai"
                )
        embedded = reader.embedded_signatures
        if embedded:
            last = max(embedded, key=lambda s: s.signed_revision)
            if last.evaluate_signature_coverage().name != "ENTIRE_FILE":
                raise ProblemError(
                    422,
                    "pdf_modified_after_signature",
                    "Ada perubahan setelah tanda tangan terakhir",
                )
    except ProblemError:
        raise
    except _PARSE_ERRORS as exc:
        raise ProblemError(422, "pdf_invalid", "PDF tidak dapat diproses", str(exc)) from exc


def _escape(text: str) -> str:
    return text.replace("%", "%%")


def _stamp_text(appearance: Appearance) -> str:
    lines = ["Ditandatangani secara elektronik oleh:", "%(signer)s", _escape(appearance.position)]
    if appearance.nip:
        lines.append(f"NIP {appearance.nip}")
    # Local wall-clock time; the authoritative time is the RFC 3161 token inside the signature.
    lines.append("%(local_time)s")
    return "\n".join(lines)


def _local_time_label(now: datetime, utc_offset_hours: int, label: str) -> str:
    local = now.astimezone(timezone(timedelta(hours=utc_offset_hours)))
    return f"{local:%d-%m-%Y %H:%M:%S} {label}"


def _timestamp_time(signed: bytes, field_name: str) -> datetime:
    reader = PdfFileReader(BytesIO(signed), strict=False)
    for emb in reader.embedded_signatures:
        if emb.field_name != field_name:
            continue
        # Read the RFC 3161 token straight from the CMS unsigned attributes: pyHanko 0.38 dropped
        # the `attached_timestamp_data` shortcut, and this does not depend on its helper names.
        for attr in emb.signer_info["unsigned_attrs"] or []:
            if attr["type"].native != "signature_time_stamp_token":
                continue
            tst = attr["values"][0]["content"]
            gen_time: datetime = tst["encap_content_info"]["content"].parsed["gen_time"].native
            return gen_time
        if emb.self_reported_timestamp is not None:
            reported: datetime = emb.self_reported_timestamp
            return reported
    return datetime.now(UTC)


@dataclass(frozen=True)
class SignOutcome:
    pdf: bytes
    signed_at: datetime


def sign(
    data: bytes,
    *,
    field_name: str,
    signer: signers.Signer,
    certify: bool,
    verify_url: str,
    appearance: Appearance,
    reason: str | None,
    location: str | None,
    tsa_url: str | None,
    utc_offset_hours: int = 7,
    timezone_label: str = "WIB",
) -> SignOutcome:
    """Fill `field_name` with a QR stamp and a PAdES signature (B-T when a TSA is configured)."""
    meta = signers.PdfSignatureMetadata(
        field_name=field_name,
        md_algorithm="sha256",
        subfilter=fields.SigSeedSubFilter.PADES,
        certify=certify,
        docmdp_permissions=fields.MDPPerm.FILL_FORMS,
        reason=reason,
        location=location,
        name=appearance.name,
    )
    timestamper = timestamps.HTTPTimeStamper(tsa_url, timeout=20) if tsa_url else None
    pdf_signer = signers.PdfSigner(
        meta,
        signer=signer,
        timestamper=timestamper,
        stamp_style=stamp.QRStampStyle(stamp_text=_stamp_text(appearance), border_width=0),
    )
    writer = IncrementalPdfFileWriter(BytesIO(data), strict=False)
    try:
        out = pdf_signer.sign_pdf(
            writer,
            appearance_text_params={
                "url": verify_url,
                "local_time": _local_time_label(
                    datetime.now(UTC), utc_offset_hours, timezone_label
                ),
            },
        )
    except timestamps.TimestampRequestError as exc:
        raise ProblemError(503, "tsa_unavailable", "Timestamp authority tidak merespons") from exc
    signed: bytes = out.getvalue()
    return SignOutcome(pdf=signed, signed_at=_timestamp_time(signed, field_name))


@lru_cache(maxsize=1)
def public_trust_roots() -> tuple[x509.Certificate, ...]:
    """Public CA roots (certifi bundle) used only to validate TSA timestamp tokens."""
    with open(certifi.where(), "rb") as fh:
        bundle = fh.read()
    return tuple(
        x509.Certificate.load(der) for _type, _hdr, der in pem.unarmor(bundle, multiple=True)
    )


RevocationLookup = Callable[[str], datetime | None]
KeyIdLookup = Callable[[str], str | None]


def validate(
    data: bytes,
    *,
    root: x509.Certificate,
    intermediate: x509.Certificate,
    revoked_at: RevocationLookup,
    key_id_for_serial: KeyIdLookup,
    require_timestamp: bool,
    crl_this_update: datetime | None,
) -> ValidationReport:
    """Validate every embedded signature against the internal root.

    Revocation is decided from our own records: a signature counts as revoked only if the
    certificate was revoked at or before the signing time.
    """
    reader = _open(data)
    results: list[SignatureResult] = []
    modified = False
    try:
        embedded = sorted(reader.embedded_signatures, key=lambda s: s.signed_revision)
        for emb in embedded:
            signer_ctx = ValidationContext(
                trust_roots=[root],
                other_certs=[intermediate],
                allow_fetching=False,
                revocation_mode="soft-fail",
            )
            ts_ctx = ValidationContext(
                trust_roots=list(public_trust_roots()),
                allow_fetching=False,
                revocation_mode="soft-fail",
            )
            status = validate_pdf_signature(
                emb, signer_validation_context=signer_ctx, ts_validation_context=ts_ctx
            )
            ts_status = status.timestamp_validity
            timestamp_valid = bool(ts_status is not None and ts_status.intact and ts_status.valid)
            signed_at: datetime | None = (
                ts_status.timestamp if ts_status is not None else status.signer_reported_dt
            )
            cert = status.signing_cert
            serial = format(cert.serial_number, "x")
            rev_time = revoked_at(serial)
            revoked = rev_time is not None and (signed_at is None or rev_time <= signed_at)
            mod_level = status.modification_level
            if (mod_level is not None and mod_level.name == "OTHER") or status.docmdp_ok is False:
                modified = True
            problems: list[str] = []
            if not status.intact:
                problems.append("Isi yang ditandatangani telah berubah")
            if not status.trusted:
                problems.append("Sertifikat tidak diterbitkan oleh CA instansi")
            if revoked:
                problems.append("Sertifikat dicabut sebelum waktu tanda tangan")
            if require_timestamp and not timestamp_valid:
                problems.append("Timestamp tidak ada atau tidak valid")
            docmdp = emb.docmdp_level
            results.append(
                SignatureResult(
                    field_name=emb.field_name,
                    signer_name=cert.subject.native.get("common_name"),
                    certificate_serial=serial,
                    key_id=key_id_for_serial(serial),
                    signed_at=signed_at,
                    timestamp_valid=timestamp_valid,
                    intact=bool(status.intact),
                    trusted=bool(status.trusted),
                    revoked=revoked,
                    valid=not problems and bool(status.valid),
                    certification_level=_MDP_NAMES.get(docmdp.name) if docmdp else None,
                    coverage=_COVERAGE.get(status.coverage.name, "partial")
                    if status.coverage is not None
                    else "partial",
                    problems=problems,
                )
            )
    except _PARSE_ERRORS as exc:
        raise ProblemError(422, "pdf_invalid", "PDF tidak dapat diproses", str(exc)) from exc

    if embedded:
        last = embedded[-1]
        if last.evaluate_signature_coverage().name != "ENTIRE_FILE":
            modified = True

    overall: Literal["valid", "invalid", "unsigned"]
    if not results:
        overall = "unsigned"
    elif modified or not all(r.valid for r in results):
        overall = "invalid"
    else:
        overall = "valid"
    return ValidationReport(
        document_sha256=sha256_hex(data),
        status=overall,
        modified_after_last_signature=modified,
        signatures=results,
        crl_this_update=crl_this_update,
    )
