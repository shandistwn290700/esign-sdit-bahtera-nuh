"""FastAPI application. Endpoints are sync `def` so pyHanko/PKCS#11 work runs in the threadpool."""

from __future__ import annotations

import hmac
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from typing import Annotated

from fastapi import APIRouter, Depends, FastAPI, File, Form, Header, Path, Request, UploadFile
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse, Response
from pydantic import ValidationError

from signer import __version__, services
from signer.config import get_settings
from signer.errors import ProblemError, problem_handler, validation_handler
from signer.models import (
    KEY_ID_PATTERN,
    AuditAnchorRequest,
    AuditAnchorResponse,
    CreateSignerRequest,
    CreateSignerResponse,
    Health,
    PrepareMeta,
    ResetTotpRequest,
    RevokeRequest,
    SignerOut,
    SignMeta,
    TotpCode,
    TotpEnrollment,
    ValidationReport,
)
from signer.services import Services


def get_services(request: Request) -> Services:
    svc: Services = request.app.state.services
    return svc


Svc = Annotated[Services, Depends(get_services)]
RequestId = Annotated[str | None, Header(alias="X-Request-Id", max_length=64)]
KeyId = Annotated[str, Path(pattern=KEY_ID_PATTERN)]


def require_token(request: Request, svc: Svc) -> None:
    scheme, _, token = request.headers.get("authorization", "").partition(" ")
    accepted = svc.settings.token_list()
    ok = scheme.lower() == "bearer" and any(
        hmac.compare_digest(token.encode(), t) for t in accepted
    )
    if not ok:
        raise ProblemError(
            401, "unauthorized", "Token tidak valid", headers={"WWW-Authenticate": "Bearer"}
        )


v1 = APIRouter(prefix="/v1", dependencies=[Depends(require_token)])
public = APIRouter()


def _read_pdf(svc: Services, upload: UploadFile) -> bytes:
    limit = svc.settings.max_pdf_bytes
    data = upload.file.read(limit + 1)
    if len(data) > limit:
        raise ProblemError(413, "pdf_too_large", "PDF melebihi batas ukuran", f"maks {limit} byte")
    if b"%PDF-" not in data[:1024]:
        raise ProblemError(422, "pdf_invalid", "Berkas bukan PDF")
    return data


def _pdf_response(result: services.PdfResult, extra: dict[str, str] | None = None) -> Response:
    headers = {"X-Input-SHA256": result.input_sha256, "X-Output-SHA256": result.output_sha256}
    headers.update(extra or {})
    return Response(content=result.pdf, media_type="application/pdf", headers=headers)


@v1.post("/signers", status_code=201)
def create_signer(
    body: CreateSignerRequest, svc: Svc, request_id: RequestId = None
) -> CreateSignerResponse:
    return services.create_signer(svc, body, request_id)


@v1.get("/signers/{key_id}")
def get_signer(key_id: KeyId, svc: Svc) -> SignerOut:
    rec = svc.store.get_signer(key_id)
    if rec is None:
        raise ProblemError(404, "signer_not_found", "Penanda tangan tidak ditemukan")
    return services.signer_out(rec)


@v1.post("/signers/{key_id}/totp/confirm")
def confirm_totp(
    key_id: KeyId, body: TotpCode, svc: Svc, request_id: RequestId = None
) -> SignerOut:
    return services.confirm_totp(svc, key_id, body.totp_code, request_id)


@v1.post("/signers/{key_id}/totp/reset")
def reset_totp(
    key_id: KeyId, body: ResetTotpRequest, svc: Svc, request_id: RequestId = None
) -> TotpEnrollment:
    return services.reset_totp(svc, key_id, body, request_id)


@v1.post("/signers/{key_id}/revoke")
def revoke_signer(
    key_id: KeyId, body: RevokeRequest, svc: Svc, request_id: RequestId = None
) -> SignerOut:
    return services.revoke_signer(svc, key_id, body, request_id)


@v1.post("/documents/prepare")
def prepare_document(
    svc: Svc,
    file: Annotated[UploadFile, File()],
    meta: Annotated[str, Form()],
    request_id: RequestId = None,
) -> Response:
    parsed = PrepareMeta.model_validate_json(meta)
    return _pdf_response(services.prepare_document(svc, _read_pdf(svc, file), parsed, request_id))


@v1.post("/documents/sign")
def sign_document(
    svc: Svc,
    file: Annotated[UploadFile, File()],
    meta: Annotated[str, Form()],
    request_id: RequestId = None,
) -> Response:
    parsed = SignMeta.model_validate_json(meta)
    result = services.sign_document(svc, _read_pdf(svc, file), parsed, request_id)
    extra = {
        "X-Signed-At": result.signed_at.isoformat(),
        "X-Certificate-Serial": result.certificate_serial,
    }
    if svc.settings.tsa_url:
        extra["X-Timestamp-Authority"] = svc.settings.tsa_url
    return _pdf_response(result, extra)


@v1.post("/documents/validate")
def validate_document(svc: Svc, file: Annotated[UploadFile, File()]) -> ValidationReport:
    return services.validate_document(svc, _read_pdf(svc, file))


@v1.get("/pki/chain")
def ca_chain(svc: Svc) -> Response:
    return Response(content=svc.ca.chain_pem(), media_type="application/pem-certificate-chain")


@v1.get("/pki/crl")
def crl(svc: Svc) -> Response:
    current = svc.ca.current_crl()
    next_update = current["tbs_cert_list"]["next_update"].native
    return Response(
        content=current.dump(),
        media_type="application/pkix-crl",
        headers={"X-CRL-Next-Update": next_update.isoformat()},
    )


@v1.post("/attestations/audit-anchor")
def audit_anchor(
    body: AuditAnchorRequest, svc: Svc, request_id: RequestId = None
) -> AuditAnchorResponse:
    return services.sign_audit_anchor(svc, body, request_id)


@public.get("/health")
def health(svc: Svc) -> JSONResponse:
    ok, checks = services.health(svc)
    body = Health(status="ok" if ok else "degraded", checks=checks, version=__version__)
    return JSONResponse(body.model_dump(), status_code=200 if ok else 503)


def create_app(svc: Services | None = None) -> FastAPI:
    """Build the app. Pass `svc` in tests; otherwise services are built from the environment."""

    @asynccontextmanager
    async def lifespan(app: FastAPI) -> AsyncIterator[None]:
        owned = app.state.services is None
        if owned:
            app.state.services = services.build_services(get_settings())
        try:
            yield
        finally:
            if owned:
                app.state.services.close()

    app = FastAPI(
        title="TTE Signing Service",
        version=__version__,
        lifespan=lifespan,
        docs_url=None,
        redoc_url=None,
        openapi_url=None,
    )
    app.state.services = svc
    app.add_exception_handler(ProblemError, problem_handler)
    app.add_exception_handler(RequestValidationError, validation_handler)
    app.add_exception_handler(ValidationError, validation_handler)
    app.include_router(v1)
    app.include_router(public)
    return app
