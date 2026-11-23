# ADR-0003: Autentikasi password + TOTP, TOTP tanda tangan diverifikasi di Signing Service

## Status
Accepted — 2026-11-21 (menggantikan rencana SSO Microsoft Entra ID; tidak ada akses admin tenant)

## Context
Aksi tanda tangan harus membuktikan niat dan identitas penanda tangan, dan tidak boleh bisa
dipalsukan hanya dengan menguasai aplikasi web.

## Decision
- Login: email + password (Laravel Fortify), 2FA TOTP wajib untuk semua akun.
- Setiap aksi tanda tangan: penanda tangan memasukkan kode TOTP **khusus tanda tangan** yang
  secret-nya hanya tersimpan (terenkripsi) di Signing Service dan diverifikasi di sana.
- Kode TOTP sekali pakai (anti-replay), rate limit 5 percobaan / 15 menit per key_id.

## Alternatives Considered
- **TOTP dicek di Laravel** — lebih sederhana, tapi Laravel yang dibobol bisa memalsukan tanda tangan.
- **PIN tanda tangan** — lebih mudah, rawan ditebak/dicuri.
- **Passkey/WebAuthn** — paling kuat, tapi verifikasinya di Signing Service lebih rumit; kandidat v2.

## Consequences
- Positif: kompromi Laravel saja tidak cukup untuk memalsukan tanda tangan.
- Negatif: penanda tangan punya dua entri TOTP (login & tanda tangan); reset TOTP butuh prosedur admin.
