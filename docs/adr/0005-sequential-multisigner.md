# ADR-0005: Alur multi-penanda tangan berurutan dengan field dibuat di awal

## Status
Accepted — 2026-11-21

## Context
Satu dokumen dapat butuh beberapa tanda tangan berurutan (mis. Wakasek → Kepala Sekolah).
Tanda tangan berikutnya tidak boleh membatalkan tanda tangan sebelumnya.

## Decision
- Saat dokumen diajukan, semua signature field kosong (posisi QR) dibuat sekaligus
  (`/documents/prepare`). Hash dokumen asli dicatat.
- Setiap penanda tangan mengisi field miliknya sebagai incremental update PAdES.
- Tanda tangan pertama memakai DocMDP level 2 (hanya pengisian form/tanda tangan yang diizinkan).
- State machine di Laravel: `draft → in_progress(step n) → completed | rejected | revoked`.
  Penanda tangan dapat menolak dengan alasan; dokumen kembali ke pembuat.

## Alternatives Considered
- **Stempel QR ditambahkan saat giliran tiba** — memodifikasi halaman setelah tanda tangan pertama
  dapat dianggap perubahan tidak sah oleh validator.

## Consequences
- Positif: semua tanda tangan tetap valid dan dapat divalidasi di Adobe/pyHanko.
- Negatif: posisi dan urutan penanda tangan tidak bisa diubah setelah diajukan (harus ajukan ulang).
