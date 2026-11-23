"""Pydantic request/response models mirroring openapi.yaml components."""

from __future__ import annotations

from datetime import date, datetime
from typing import Literal, Self
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field, model_validator

KEY_ID_PATTERN = r"^sk_[0-9A-HJKMNP-TV-Z]{26}$"
SHA256_PATTERN = r"^[0-9a-f]{64}$"
FIELD_PATTERN = r"^sig_[0-9]{1,2}$"
NIP_PATTERN = r"^[0-9]{18}$"
TOTP_PATTERN = r"^[0-9]{6}$"


class _Strict(BaseModel):
    model_config = ConfigDict(extra="forbid")


class CreateSignerRequest(_Strict):
    signer_ref: UUID
    full_name: str = Field(min_length=2, max_length=128)
    nip: str | None = Field(default=None, pattern=NIP_PATTERN)
    position: str = Field(min_length=1, max_length=128)
    organization_unit: str | None = Field(default=None, max_length=128)
    email: str = Field(max_length=254, pattern=r"^[^@\s]+@[^@\s]+\.[^@\s]+$")
    actor_ref: UUID | None = None


class CertificateInfo(BaseModel):
    serial: str
    subject: str
    not_before: datetime
    not_after: datetime
    pem: str
    sha256_fingerprint: str


class SignerOut(BaseModel):
    key_id: str
    signer_ref: UUID
    status: Literal["active", "revoked", "expired"]
    totp_status: Literal["pending", "active"]
    revoked_at: datetime | None
    revocation_reason: str | None
    certificate: CertificateInfo


class TotpEnrollment(BaseModel):
    otpauth_uri: str
    status: Literal["pending"] = "pending"


class CreateSignerResponse(SignerOut):
    totp_enrollment: TotpEnrollment


class TotpCode(_Strict):
    totp_code: str = Field(pattern=TOTP_PATTERN)


class ResetTotpRequest(_Strict):
    actor_ref: UUID
    reason: str = Field(min_length=5, max_length=500)


RevocationReason = Literal[
    "unspecified", "key_compromise", "affiliation_changed", "superseded", "cessation_of_operation"
]


class RevokeRequest(_Strict):
    reason: RevocationReason
    note: str | None = Field(default=None, max_length=500)
    actor_ref: UUID


class FieldPlacement(_Strict):
    name: str = Field(pattern=FIELD_PATTERN)
    order: int = Field(ge=1, le=10)
    page: int = Field(ge=1)
    x: float = Field(ge=0)
    y: float = Field(ge=0)
    width: float = Field(ge=72, le=400)
    height: float = Field(ge=72, le=400)

    @model_validator(mode="after")
    def _name_matches_order(self) -> Self:
        if self.name != f"sig_{self.order}":
            raise ValueError(f"name must be sig_{self.order} for order {self.order}")
        return self


class PrepareMeta(_Strict):
    input_sha256: str = Field(pattern=SHA256_PATTERN)
    fields: list[FieldPlacement] = Field(min_length=1, max_length=10)

    @model_validator(mode="after")
    def _orders_contiguous(self) -> Self:
        orders = sorted(f.order for f in self.fields)
        if orders != list(range(1, len(orders) + 1)):
            raise ValueError("field orders must be 1..n without gaps or duplicates")
        return self


class Appearance(_Strict):
    name: str = Field(min_length=1, max_length=128)
    position: str = Field(min_length=1, max_length=128)
    nip: str | None = Field(default=None, pattern=NIP_PATTERN)


class SignMeta(_Strict):
    input_sha256: str = Field(pattern=SHA256_PATTERN)
    field_name: str = Field(pattern=FIELD_PATTERN)
    key_id: str = Field(pattern=KEY_ID_PATTERN)
    totp_code: str = Field(pattern=TOTP_PATTERN)
    verify_url: str = Field(pattern=r"^https://", max_length=2048)
    certify: bool = False
    appearance: Appearance
    reason: str | None = Field(default=None, max_length=200)
    location: str | None = Field(default=None, max_length=100)
    actor_ref: UUID | None = None

    @model_validator(mode="after")
    def _certify_first_only(self) -> Self:
        if self.certify and self.field_name != "sig_1":
            raise ValueError("certify is only allowed for sig_1")
        return self


class SignatureResult(BaseModel):
    field_name: str
    signer_name: str | None
    certificate_serial: str | None
    key_id: str | None
    signed_at: datetime | None
    timestamp_valid: bool
    intact: bool
    trusted: bool
    revoked: bool
    valid: bool
    certification_level: Literal["no_changes", "form_filling", "annotations"] | None
    coverage: Literal["entire_file", "entire_revision", "partial"]
    problems: list[str]


class ValidationReport(BaseModel):
    document_sha256: str
    status: Literal["valid", "invalid", "unsigned"]
    modified_after_last_signature: bool
    signatures: list[SignatureResult]
    crl_this_update: datetime | None


class AuditAnchorRequest(_Strict):
    chain_head_sha256: str = Field(pattern=SHA256_PATTERN)
    last_entry_id: int = Field(ge=1)
    anchored_date: date


class AuditAnchorResponse(BaseModel):
    cms_signature: str
    certificate_serial: str
    signed_at: datetime


class Health(BaseModel):
    status: Literal["ok", "degraded", "down"]
    checks: dict[str, str]
    version: str
