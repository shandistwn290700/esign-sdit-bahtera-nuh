# ADR-0009: Deploy satu VPS via Coolify

## Status
Accepted — 2026-11-21

## Decision
- Satu project/environment Coolify berisi: Laravel app, Horizon worker, Signing Service,
  PostgreSQL, Redis, MinIO.
- Signing Service **tanpa domain dan tanpa port publik**; dipanggil via nama host internal.
- Secret (PIN HSM, token API, kunci MinIO, kredensial WA/SMTP) disimpan sebagai environment
  secret Coolify, tidak di repository.
- Backup harian terenkripsi off-site: PostgreSQL, MinIO, volume SoftHSM. Uji restore tiap 3 bulan.
- Spesifikasi awal: 4 vCPU, 8 GB RAM, 100 GB SSD.

## Alternatives Considered
- **Managed cloud (KMS, managed DB)** — operasional lebih ringan, lebih mahal, data keluar dari kendali instansi.
- **On-premise** — paling tertutup, perawatan perangkat keras sepenuhnya oleh tim.

## Consequences
- Positif: murah, data di server sendiri.
- Negatif: single point of failure; dimitigasi dengan backup + prosedur restore terdokumentasi.
