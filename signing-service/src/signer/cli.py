"""Command line entry point.

    tte-signer serve                 run the HTTP server
    tte-signer init-dev-ca           throwaway development CA (refused in production)
    tte-signer ca-csr [--out FILE]   production: intermediate key in the HSM, print its CSR
    tte-signer ca-import --root R --intermediate I
                                     production: install offline-root-signed certificates
See docs/runbooks/root-ca-offline.md.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

from signer.config import get_settings

RESTART_HINT = "Restart service agar sesi HSM memuat kunci baru:"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="tte-signer")
    sub = parser.add_subparsers(dest="command", required=True)
    serve = sub.add_parser("serve", help="run the HTTP server")
    serve.add_argument("--host", default="0.0.0.0")  # noqa: S104 (internal Docker network only)
    serve.add_argument("--port", type=int, default=8000)
    sub.add_parser("init-dev-ca", help="create a throwaway development CA (not for production)")
    csr = sub.add_parser("ca-csr", help="create the intermediate CA key in the HSM and print a CSR")
    csr.add_argument("--out", type=Path, help="write the CSR here instead of stdout")
    imp = sub.add_parser("ca-import", help="install the offline root and signed intermediate")
    imp.add_argument("--root", type=Path, required=True, help="root CA certificate (PEM)")
    imp.add_argument("--intermediate", type=Path, required=True, help="signed intermediate (PEM)")
    args = parser.parse_args(argv)

    if args.command == "serve":
        import uvicorn

        from signer.app import create_app

        uvicorn.run(create_app(), host=args.host, port=args.port, proxy_headers=False)
        return 0

    settings = get_settings()
    from signer.services import build_services

    if args.command == "init-dev-ca":
        if settings.app_env == "production":
            print("init-dev-ca ditolak: SIGNER_APP_ENV=production. Gunakan root CA offline.")
            return 2
        from signer.bootstrap import init_dev_ca

        svc = build_services(settings)
        try:
            created = init_dev_ca(svc, root_key_out=settings.data_dir / "dev-root-key.pem")
        finally:
            svc.close()
        if created:
            print(f"CA development dibuat. {RESTART_HINT}")
            print("  docker compose -f compose.dev.yml restart signer")
        else:
            print("CA sudah ada, tidak ada perubahan.")
        return 0

    from signer.production_ca import CaSetupError, create_intermediate_csr, import_ca_certificates

    svc = build_services(settings)
    try:
        if args.command == "ca-csr":
            pem = create_intermediate_csr(svc)
            if args.out:
                args.out.write_text(pem)
                print(f"CSR ditulis ke {args.out}.")
                print("Bawa ke mesin Root offline untuk ditandatangani.")
            else:
                sys.stdout.write(pem)
            return 0

        fingerprints = import_ca_certificates(
            svc, args.root.read_bytes(), args.intermediate.read_bytes()
        )
        print("CA terpasang. Cocokkan sidik jari ini dengan catatan di mesin offline:")
        print(f"  Root          SHA-256 {fingerprints['root_sha256']}")
        print(f"  CA perantara  SHA-256 {fingerprints['intermediate_sha256']}")
        print(RESTART_HINT)
        print("  (Coolify: Restart pada service signer)")
        return 0
    except CaSetupError as exc:
        print(f"Ditolak: {exc}", file=sys.stderr)
        return 1
    finally:
        svc.close()


if __name__ == "__main__":
    sys.exit(main())
