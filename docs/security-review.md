# Review Keamanan Pra-Go-Live

Tanggal: 2026-11-23 · Cakupan: `app-laravel/` dan `signing-service/` · Divisi: `secure-code-guardian`

Fokus review: alur kunci dan HSM, TOTP tanda tangan, otorisasi dokumen, verifikasi publik, audit log, dan konfigurasi produksi.

## Temuan yang sudah diperbaiki

| # | Tingkat | Temuan | Perbaikan |
|---|---|---|---|
| 1 | Tinggi | Penanda tangan bisa mengganti namanya sendiri di Profil. Nama itu tercetak di stempel dan halaman verifikasi publik, sehingga seseorang bisa menandatangani dengan nama pejabat lain. | Nama dikunci setelah kunci tanda tangan diterbitkan. Hanya admin yang boleh mengubahnya. |
| 2 | Tinggi | Halaman Pengaturan (aktivasi tanda tangan, profil) tidak mewajibkan 2FA login. Jika seorang penanda tangan belum memasang 2FA, pemegang kata sandinya saja bisa mengaktifkan autentikator tanda tangan, lalu menandatangani. | Route tersebut kini lewat `EnsureTwoFactorEnabled`. Hanya halaman Keamanan (tempat memasang 2FA) yang terbuka sebelum 2FA aktif. |
| 3 | Sedang | Akun nonaktif (`is_active = false`) masih bisa login. | `Fortify::authenticateUsing` menolaknya dengan pesan yang sama seperti kata sandi salah. Waktu proses dibuat sama agar keberadaan akun tidak bocor. Sesi yang masih terbuka diputus. |
| 4 | Sedang | Email bisa diganti tanpa konfirmasi kata sandi. Sesi yang dibajak cukup untuk mengalihkan reset kata sandi, lalu mengambil alih akun. | Halaman dan aksi Profil wajib konfirmasi kata sandi. Setiap perubahan nama/email dicatat di audit log (`user.profile_updated`). |
| 5 | Sedang | Tidak ada header keamanan. | Middleware `SecurityHeaders` menambahkan: CSP ketat dengan nonce (tanpa `unsafe-eval`), `frame-ancestors 'none'` dan `X-Frame-Options: DENY` (cegah clickjacking pada tombol Tandatangani), `nosniff`, `Referrer-Policy`, `Permissions-Policy`, serta HSTS dan COOP saat HTTPS. |
| 6 | Rendah | Cookie `appearance` (tidak terenkripsi) disisipkan ke script inline. Escaping Blade sudah mencegah eksploit, tetapi tetap rapuh. | Nilai cookie dibatasi ke `light`/`dark`/`system`. |
| 7 | Rendah | Cookie sesi produksi belum dipaksa `Secure`. | Aplikasi menolak start di produksi jika `SESSION_SECURE_COOKIE` bukan `true`. |
| 8 | Rendah | Pesan "lupa kata sandi" membedakan email terdaftar dan tidak terdaftar. | Kedua kasus kini memakai pesan yang sama (`lang/id/passwords.php`). |

Setiap perbaikan punya tes regresi di `tests/Feature/SecurityHardeningTest.php`. Uji browser end-to-end lulus dengan CSP aktif: pdf.js, geser kotak QR, tanda tangan, dan verifikasi, tanpa error JS.

## Yang diperiksa dan sudah baik

- **Signing Service**
  - Token API dibandingkan secara *constant-time*, minimal 32 karakter.
  - `/docs` dan `/openapi.json` dimatikan; ukuran PDF dibatasi.
  - Kunci tidak dapat diekspor dari HSM.
- **TOTP tanda tangan**
  - Diverifikasi di dalam Signing Service, bukan di Laravel.
  - Ada anti-replay per langkah waktu dan lockout setelah percobaan gagal.
  - Admin tidak pernah melihat secret-nya.
- **Otorisasi dokumen**
  - Policy `view` membatasi akses ke admin, pembuat, dan penanda tangan.
  - Tanda tangan hanya untuk penanda tangan giliran saat ini, di bawah kunci baris (`lockForUpdate`).
  - Hash input dicocokkan dengan hasil Signing Service.
  - Penanda tangan divalidasi `canSign()` saat pengajuan.
- **Halaman publik**
  - Hanya menampilkan status, nama dan jabatan penanda tangan, serta waktu tanda tangan.
  - Judul dan nomor hanya tampil jika pembuat mengizinkan; isi dokumen tidak pernah tampil.
  - Ada rate limit.
- **Penyimpanan dan audit**
  - Penyimpanan dienkripsi AES-256-GCM.
  - Unduhan memakai `Cache-Control: no-store` dan dicatat di audit log.
  - Audit log memakai hash-chain dan trigger append-only di PostgreSQL.
- **Autentikasi**
  - Rate limit pada login, 2FA, tanda tangan, upload, dan verifikasi.
  - Kebijakan kata sandi produksi: minimal 12 karakter, kombinasi, dan belum pernah bocor.
- **Dependensi:** `composer audit` dan `npm audit` bersih per 2026-11-23.

## Belum ditangani (perlu keputusan atau tahap berikutnya)

1. ~~Belum ada menu admin untuk menonaktifkan pengguna atau mengoreksi nama.~~ **Selesai 2026-11-23.** Admin dapat mengubah data, menonaktifkan (sesi langsung diputus, sertifikat dapat ikut dicabut), dan mengaktifkan kembali pengguna. Perubahan nama, jabatan, email, atau NIP penanda tangan wajib disertai penerbitan ulang sertifikat. Admin tidak dapat menonaktifkan atau menurunkan peran dirinya sendiri. Lihat `AdminUserManagementTest` dan `tests/e2e/admin.mjs`.
2. **`SESSION_ENCRYPT=true`** disarankan di produksi. Ini masuk konfigurasi deploy.
3. **Isolasi jaringan Signing Service, PIN HSM, dan backup kunci dokumen** akan ditangani di tahap deploy oleh `devops-engineer`.
4. **Prosedur Root CA offline** masih perlu ditulis sebagai runbook.
5. **`pip-audit` untuk Signing Service** belum dijalankan karena container uji tidak punya akses internet. Jalankan di CI.

## Review lanjutan — 2026-11-24 (setelah deploy produksi)

Cakupan: kode yang ditambahkan setelah review pertama, yaitu `tte:bootstrap-admin`, pengalihan
HTTPS di belakang Cloudflare, unggah logo (termasuk SVG), hapus permanen dokumen yang dicabut,
kelola pengguna, halaman Semua dokumen, dan unduh PDF. Juga dicek: hasil pemindaian image Coolify.

| # | Tingkat | Temuan | Perbaikan |
|---|---|---|---|
| 9 | Sedang | Di belakang Cloudflare semua proxy dipercaya, sedangkan Cloudflare *menambahkan* ke `X-Forwarded-For` kiriman pengunjung. IP klien bisa dipalsukan, sehingga batas percobaan login (email + IP) bisa dilewati dan IP di audit log bisa dipalsukan. | IP klien diambil dari `CF-Connecting-IP`, yang diisi Cloudflare sendiri. Tes regresi membuktikan IP palsu `1.2.3.4` sebelumnya terbaca. |
| 10 | Tinggi | Image Signing Service: Starlette 0.46.2 (termasuk CVE-2026-48710, ada di daftar KEV) dan cryptography 44.0.3. | Python 3.13, FastAPI 0.165, Starlette 1.10, cryptography 50.0.2, pyHanko 0.38.2, python-pkcs11 0.9.5. Diverifikasi dengan smoke test SoftHSM + TSA sungguhan. |
| 11 | Tinggi | Image backup: paket `rclone` Alpine membawa CVE kritis (satu di KEV), padahal fitur off-site belum dipakai. | `rclone` dihapus dari image, ditambah `apk upgrade`. Akan dipasang ulang (versi terbaru, dipin) saat tujuan off-site dipilih. |
| 12 | Sedang | `LOG_LEVEL=debug` sementara di produksi, sehingga tautan reset password tercatat di log. | Kembalikan ke `warning` setelah SMTP aktif (tercatat sebagai tindak lanjut). |

Diperiksa dan aman:
- **Bootstrap admin:** password tidak pernah dicetak, divalidasi dengan aturan produksi, dan tidak berbuat apa-apa jika admin sudah ada.
- **Logo SVG:** disajikan dengan CSP `sandbox` + `nosniff` dan nama berkas acak.
- **Hapus dokumen:** hanya untuk dokumen yang dicabut, oleh pembuat atau admin, dengan konfirmasi ketik dan snapshot audit.
- **Pencarian admin:** wildcard `LIKE` di-escape.
- **Nama berkas unduhan:** berupa slug, sehingga tidak bisa dipakai untuk injeksi header.
