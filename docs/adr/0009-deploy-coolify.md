# ADR-0009: Deploy satu VPS via Coolify

## Status
Accepted — 2026-11-21. **Amended 2026-11-23** (implementasi).

## Amendment 2026-11-23
Implementasi ada di `compose.prod.yml` (lihat docs/runbooks/deploy-coolify.md). Perbedaan dari rencana awal:
- **Tanpa MinIO**: PDF terenkripsi disimpan di volume `app-storage` (ADR-0007 amended).
- **Tanpa Horizon**: service `worker` (`queue:work`) dan `scheduler` (`schedule:work`) memakai image yang sama dengan `web`.
- **Image web**: `serversideup/php` 8.4 FPM + Nginx (non-root, port 8080), versi dipin.
- **Backup**: service `backup` harian 02.00 WIB. Isinya pg_dump, storage, data Signing Service (SQLite + token SoftHSM), dan manifest SHA-256, dienkripsi ke kunci **publik** age. Kunci privatnya offline, jadi server tidak bisa membaca backup-nya sendiri. Salinan off-site opsional lewat rclone. Rahasia (kunci dokumen, PIN HSM) tidak ikut backup dan disimpan offline. Lihat docs/runbooks/backup-restore.md.
- **Root CA** offline (docs/runbooks/root-ca-offline.md).

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
