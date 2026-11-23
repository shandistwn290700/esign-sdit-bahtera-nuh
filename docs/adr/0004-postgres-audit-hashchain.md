# ADR-0004: PostgreSQL + audit log hash-chain

## Status
Accepted — 2026-11-21

## Context
Butuh data relasional (dokumen, tahap, pengguna) dengan transaksi, dan audit log yang dapat
membuktikan tidak pernah diubah.

## Decision
- PostgreSQL sebagai database utama.
- Tabel `audit_logs`: setiap baris menyimpan `prev_hash` dan `hash = SHA256(prev_hash || payload)`.
- Role database aplikasi hanya punya INSERT/SELECT pada `audit_logs` (tanpa UPDATE/DELETE).
- Setiap hari, hash terakhir ditandatangani Signing Service dan dikirim ke luar server (email arsip).
- Perintah `php artisan audit:verify` untuk memeriksa rantai.

## Alternatives Considered
- **MySQL** — setara untuk skala ini; PostgreSQL dipilih karena kontrol privilege per role dan JSONB.
- **Blockchain/ledger service** — berlebihan untuk satu instansi.

## Consequences
- Positif: manipulasi log terdeteksi; murah dan sederhana.
- Negatif: penyerang dengan akses superuser DB masih bisa menulis ulang rantai — dimitigasi oleh
  anchor harian di luar server.
