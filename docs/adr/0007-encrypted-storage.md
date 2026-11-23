# ADR-0007: Penyimpanan dokumen terenkripsi

## Status
Accepted — 2026-11-21

## Decision
- PDF (asli, prepared, tiap versi bertanda tangan) disimpan di MinIO dengan server-side
  encryption, bucket private, akses hanya lewat Laravel.
- Download hanya untuk pembuat, penanda tangan dokumen itu, dan admin; setiap download dicatat
  di audit log.
- Database menyimpan hash SHA-256 setiap versi.

## Alternatives Considered
- **Disk lokal + enkripsi aplikasi (Laravel Crypt)** — lebih sederhana dan mengurangi satu container.
  MinIO dipilih agar mudah dipindah ke penyimpanan S3-compatible lain.

## Consequences
- Negatif: satu container tambahan untuk dioperasikan dan dibackup.

## Amendment — 2026-11-21
**Status:** Accepted, supersedes the MinIO decision above for v1.

PDF versions are stored on a private local disk (`documents`, never served directly), each file
encrypted with AES-256-GCM using a dedicated `DOCUMENT_ENCRYPTION_KEY` (separate from `APP_KEY`
so either key can be rotated independently). The SHA-256 of every version is stored in the
database and re-checked on every read (`App\Services\DocumentStorage`).

Reasons: one fewer container to operate and back up on a single VPS; MinIO's community image
distribution has become uncertain. Moving to S3-compatible storage later only requires changing
the `documents` disk driver.

Consequence: the backup must include `storage/app/documents` **and** the key (stored apart from
the files). Losing the key makes every document unreadable.
