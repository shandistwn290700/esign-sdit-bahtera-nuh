# Kontrak Signing Service — Catatan Integrasi Laravel

Spesifikasi lengkap: [`signing-service/openapi.yaml`](../signing-service/openapi.yaml) (OpenAPI 3.1, `/v1`).
Status: **Draft — menunggu persetujuan divisi Backend.**

## Ringkasan endpoint

| Operasi | Endpoint | Dipanggil dari |
|---|---|---|
| Daftarkan penanda tangan | `POST /v1/signers` | Admin → job |
| Aktivasi / reset TOTP | `POST /v1/signers/{key_id}/totp/confirm` · `/totp/reset` | Web (sinkron) |
| Status / cabut sertifikat | `GET /v1/signers/{key_id}` · `POST .../revoke` | Admin |
| Siapkan field | `POST /v1/documents/prepare` | Job saat dokumen diajukan |
| Tanda tangan | `POST /v1/documents/sign` | Job saat penanda tangan konfirmasi |
| Validasi | `POST /v1/documents/validate` | Halaman verifikasi publik (upload) |
| Rantai CA / CRL | `GET /v1/pki/chain` · `/v1/pki/crl` | Scheduler → cache → URL publik |
| Anchor audit | `POST /v1/attestations/audit-anchor` | Scheduler harian |

## Aturan untuk sisi Laravel

1. **Job tanda tangan `tries = 1`.** TOTP sekali pakai; retry otomatis selalu gagal `totp_replayed`.
   Gagal jaringan/`503` → tandai tahap `failed_retryable`, minta penanda tangan memasukkan kode baru.
2. **Kode TOTP tidak boleh disimpan atau di-log.** Kirim langsung dari request ke payload job
   (payload job terenkripsi: `ShouldBeEncrypted`), lalu hilang.
3. **`otpauth_uri` hanya ditampilkan sekali** sebagai QR, tidak disimpan di database atau cache.
4. **Selalu kirim `input_sha256`** dari hash yang tercatat di database untuk versi tersebut.
   Simpan `X-Output-SHA256` sebagai hash versi baru, lalu verifikasi ulang terhadap file yang
   disimpan ke MinIO.
5. **Urutan field = urutan tahap.** `sig_n` milik tahap ke-n; `certify: true` hanya untuk `sig_1`.
6. **Koordinat dalam PDF points, origin kiri-bawah.** Frontend mengonversi dari viewport pdf.js
   (`viewport.convertToPdfPoint`) sebelum dikirim.
7. **Timeout HTTP:** 60 detik untuk prepare/sign/validate (termasuk panggilan TSA), 10 detik lainnya.
8. **Kirim `X-Request-Id`** (ID korelasi yang sama dengan audit log) di setiap panggilan.
9. Petakan `Problem.code` ke pesan pengguna — jangan tampilkan `detail` mentah ke publik.

## Pemetaan error ke pesan pengguna

| `code` | Pesan untuk pengguna |
|---|---|
| `totp_invalid` | Kode salah. Periksa jam di HP dan coba lagi. |
| `totp_replayed` | Kode sudah dipakai. Tunggu kode berikutnya. |
| `totp_locked` | Terlalu banyak percobaan. Coba lagi dalam N menit. |
| `signer_totp_pending` | Aktifkan dulu aplikasi autentikator tanda tangan Anda. |
| `signer_revoked` | Sertifikat Anda dicabut. Hubungi admin. |
| `field_already_signed` / `previous_field_unsigned` | Status dokumen berubah. Muat ulang halaman. |
| `input_hash_mismatch` / `pdf_modified_after_signature` | Dokumen tidak konsisten. Hubungi admin (insiden). |
| `hsm_unavailable` | Modul kunci tanda tangan tidak dapat diakses. Coba lagi beberapa saat; bila berulang, hubungi admin. |
| `tsa_unavailable` | Server stempel waktu tidak dapat dihubungi. Coba lagi beberapa saat. |
| `ca_not_initialized` | Sertifikat CA tanda tangan belum dipasang di server. Hubungi admin (prosedur Root CA). |
| `signer_unreachable` (Laravel) | Layanan tanda tangan tidak dapat dihubungi. Coba lagi beberapa saat; bila berulang, hubungi admin. |

## Keterbatasan yang diketahui

- TOTP membuktikan kepemilikan HP penanda tangan, tetapi tidak terikat secara kriptografis
  ke dokumen tertentu. Laravel yang dibobol bisa "membelokkan" kode TOTP yang sah ke dokumen
  lain dalam jendela 30 detik. Mitigasi v1: log operasi di Signing Service mencatat hash
  dokumen per tanda tangan, dan notifikasi "Anda telah menandatangani X" dikirim ke penanda
  tangan. Solusi v2: WebAuthn dengan challenge = hash dokumen.

## Catatan implementasi (2026-11-21)

- **Tanda tangan dijalankan sinkron di request web, bukan lewat job antrian.** Alasannya: kode
  TOTP yang salah harus langsung muncul di form. Aturan "tidak boleh retry otomatis" tetap
  berlaku; baris dokumen dikunci (`lockForUpdate`) selama operasi berlangsung sehingga dua
  request tidak bisa menandatangani tahap yang sama. Lihat `App\Actions\Documents\SignDocumentStep`.
- **Aktivasi TOTP dilakukan oleh penanda tangan sendiri**, bukan admin. Admin hanya memanggil
  `createSigner` (URI dari respons ini dibuang); penanda tangan memanggil `totp/reset` dari
  halaman Pengaturan > Tanda tangan untuk mendapatkan QR, sehingga admin tidak pernah melihat secret.
- Waktu pada stempel ditampilkan dalam zona lokal (`SIGNER_STAMP_UTC_OFFSET_HOURS`, default WIB).
  Waktu resmi tetap token RFC 3161 di dalam tanda tangan.
