# Pembagian Divisi & Urutan Kerja

## Divisi

| Divisi | Skill | Tanggung jawab |
|---|---|---|
| Backend | `laravel-specialist` | Fortify auth + 2FA, model & migrasi, state machine dokumen (ADR-0005), upload ke MinIO (ADR-0007), client Signing Service, audit log hash-chain (ADR-0004), pencabutan, halaman verifikasi (server), notifikasi SMTP/WA via Horizon (ADR-0008) |
| Signing Service | `python-pro` | FastAPI + pyHanko + SoftHSM2: endpoint di architecture.md §5, CA internal (ADR-0002), TOTP tanda tangan (ADR-0003), stempel QR, timestamp, CRL, OpenAPI spec, Dockerfile |
| Frontend | `vue-expert` | Inertia + Vue 3: dashboard, unggah PDF, penempatan kotak QR dengan pdf.js, pengaturan urutan penanda tangan, layar tanda tangan + input TOTP, halaman verifikasi publik (scan & upload), panel admin |

Pendukung (dipanggil saat titik pemeriksaan):
- `secure-code-guardian` — review alur kunci, TOTP, otorisasi dokumen sebelum go-live.
- `devops-engineer` — setup Coolify, jaringan internal, backup (ADR-0009).

## Urutan kerja

1. **Kontrak dulu** — `python-pro` menulis OpenAPI Signing Service; `laravel-specialist` menyetujui.
   Setelah itu backend dan Signing Service dapat dikerjakan paralel (backend memakai mock).
2. **Signing Service inti** — CA, `/signers`, `/documents/prepare`, `/documents/sign`, `/documents/validate`.
3. **Backend inti** — auth, dokumen + tahap, integrasi Signing Service, audit log.
4. **Frontend** — mulai setelah route & props Inertia untuk alur dokumen tersedia.
5. **Fitur pelengkap** — pencabutan, notifikasi, verifikasi via upload.
6. **Review keamanan & deploy** — `secure-code-guardian`, lalu `devops-engineer`.

## Struktur repository (monorepo)

```
tte-qr/
├── docs/              # arsitektur, ADR, pembagian divisi
├── app-laravel/       # Laravel + Inertia + Vue (Backend + Frontend)
└── signing-service/   # FastAPI + pyHanko + SoftHSM2
```

## Progres (2026-11-21)

| Tahap | Status |
|---|---|
| 1. Kontrak API | Selesai |
| 2. Signing Service inti | Selesai — 26 tes, ruff + mypy --strict bersih, smoke test SoftHSM + TSA lulus |
| 3. Backend inti (Laravel 13) | Selesai — 70 tes (cakupan baris 87%), Pint bersih, tes live ke Signing Service lulus |
| 4. Frontend (Vue 3 + Inertia) | Selesai (2026-11-23) — 7 halaman, vue-tsc + lint bersih, uji browser end-to-end (Playwright) 12 langkah lulus. Jalankan: lihat `app-laravel/tests/e2e/flow.mjs`. Data demo: `php artisan tte:demo`. |
| 5. Pelengkap | Sebagian — notifikasi email selesai; WhatsApp Cloud API belum |
| 6. Review keamanan & deploy | Review selesai (2026-11-23): 8 temuan diperbaiki, lihat `docs/security-review.md`; 80 tes lulus. Deploy belum. |
| — Kelola pengguna (admin) | Selesai (2026-11-23): ubah data + terbit ulang sertifikat, nonaktif/aktif kembali; 88 tes + uji browser `admin.mjs` |
| — Semua dokumen (admin) | Selesai (2026-11-23): /admin/documents, cari judul/nomor/pembuat, filter status + jumlah, 25 per halaman; 93 tes |
| — Identitas & tampilan | Selesai (2026-11-23): menu admin Identitas aplikasi (nama + logo, SVG disajikan sandbox), tema bergaya Material Dashboard 2 (Creative Tim, MIT) di atas Vue 3 + Tailwind yang ada; 100 tes. Produksi: set SIGNER_ORG_NAME="SDIT Bahtera Nuh" sebelum membuat CA. |
| — Terjemahan Indonesia | Selesai (2026-11-23): semua halaman bawaan + `lang/id` (validasi, login, email) |
