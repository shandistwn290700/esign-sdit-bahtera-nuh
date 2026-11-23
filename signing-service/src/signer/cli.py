"""Command line entry point: `tte-signer serve` and `tte-signer init-dev-ca`."""

from __future__ import annotations

import argparse
import sys

from signer.config import get_settings


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="tte-signer")
    sub = parser.add_subparsers(dest="command", required=True)
    serve = sub.add_parser("serve", help="run the HTTP server")
    serve.add_argument("--host", default="0.0.0.0")  # noqa: S104 (internal Docker network only)
    serve.add_argument("--port", type=int, default=8000)
    sub.add_parser("init-dev-ca", help="create a throwaway development CA (not for production)")
    args = parser.parse_args(argv)

    if args.command == "serve":
        import uvicorn

        from signer.app import create_app

        uvicorn.run(create_app(), host=args.host, port=args.port, proxy_headers=False)
        return 0

    settings = get_settings()
    if settings.app_env == "production":
        print("init-dev-ca ditolak: SIGNER_APP_ENV=production. Gunakan root CA offline.")
        return 2
    from signer.bootstrap import init_dev_ca
    from signer.services import build_services

    svc = build_services(settings)
    try:
        created = init_dev_ca(svc, root_key_out=settings.data_dir / "dev-root-key.pem")
    finally:
        svc.close()
    if created:
        print("CA development dibuat. Restart service agar sesi HSM memuat kunci CA:")
        print("  docker compose -f compose.dev.yml restart signer")
    else:
        print("CA sudah ada, tidak ada perubahan.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
