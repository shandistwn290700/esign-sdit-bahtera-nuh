# syntax=docker/dockerfile:1
FROM python:3.13-slim-bookworm AS base
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1 PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PIP_DEFAULT_TIMEOUT=60 PIP_RETRIES=10
# Upgrade OS packages and pip so the image does not ship known-vulnerable versions.
RUN apt-get update \
 && apt-get install -y --no-install-recommends softhsm2 tini ca-certificates \
 && apt-get upgrade -y \
 && rm -rf /var/lib/apt/lists/* \
 && pip install --no-cache-dir --upgrade pip

# Build wheels (python-pkcs11 needs a C compiler).
FROM base AS build
RUN apt-get update \
 && apt-get install -y --no-install-recommends build-essential \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /src
COPY pyproject.toml ./
COPY src ./src
RUN pip wheel --wheel-dir /wheels .

# Test image: `docker compose -f compose.dev.yml --profile test run --rm signer-test`
FROM build AS test
RUN pip install /wheels/*.whl && pip install ".[dev]"
COPY tests ./tests
CMD ["sh", "-c", "ruff check src tests && mypy src && pytest -q"]

FROM base AS runtime
COPY --from=build /wheels /wheels
RUN pip install --no-index /wheels/*.whl && rm -rf /wheels \
 && groupadd -r signer && useradd -r -g signer -u 10001 -d /data signer \
 && mkdir -p /data && chown signer:signer /data
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh
ENV SOFTHSM2_CONF=/data/softhsm2.conf SIGNER_DATA_DIR=/data
USER signer
VOLUME /data
EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
  CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=4)"
ENTRYPOINT ["tini", "--", "entrypoint.sh"]
CMD ["tte-signer", "serve"]
