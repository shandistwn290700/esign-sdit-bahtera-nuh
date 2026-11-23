# ADR-0008: Notifikasi hanya berisi tautan

## Status
Accepted — 2026-11-21

## Decision
- Email via SMTP (akun instansi atau layanan email transaksional).
- WhatsApp via **WhatsApp Cloud API resmi** dengan template pesan yang disetujui Meta.
- Isi notifikasi: judul singkat + tautan ke aplikasi (butuh login). Tidak pernah melampirkan PDF.
- Dikirim lewat queue (Horizon) dengan retry.

## Alternatives Considered
- **Gateway WA tidak resmi** — murah, tapi berisiko nomor diblokir dan pesan melewati pihak ketiga.

## Consequences
- Negatif: Cloud API butuh verifikasi bisnis Meta dan ada biaya per percakapan.
