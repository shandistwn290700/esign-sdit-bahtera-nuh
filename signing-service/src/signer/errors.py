"""RFC 9457 problem responses with stable `code` values (see openapi.yaml)."""

from __future__ import annotations

from collections.abc import Mapping

from fastapi import Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pydantic import ValidationError


class ProblemError(Exception):
    """An error that maps directly to a problem+json response."""

    def __init__(
        self,
        status: int,
        code: str,
        title: str,
        detail: str | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> None:
        super().__init__(f"{code}: {detail or title}")
        self.status = status
        self.code = code
        self.title = title
        self.detail = detail
        self.headers = dict(headers or {})


def _response(request: Request, exc: ProblemError) -> JSONResponse:
    body: dict[str, object] = {
        "type": "about:blank",
        "title": exc.title,
        "status": exc.status,
        "code": exc.code,
    }
    if exc.detail:
        body["detail"] = exc.detail
    request_id = request.headers.get("x-request-id")
    if request_id:
        body["request_id"] = request_id
    return JSONResponse(
        body, status_code=exc.status, headers=exc.headers, media_type="application/problem+json"
    )


async def problem_handler(request: Request, exc: Exception) -> JSONResponse:
    assert isinstance(exc, ProblemError)
    return _response(request, exc)


async def validation_handler(request: Request, exc: Exception) -> JSONResponse:
    assert isinstance(exc, RequestValidationError | ValidationError)
    detail = "; ".join(
        f"{'.'.join(str(p) for p in err['loc'])}: {err['msg']}" for err in exc.errors()
    )
    return _response(request, ProblemError(400, "validation_failed", "Request tidak valid", detail))
