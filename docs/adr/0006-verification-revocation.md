# ADR-0006: Verifikasi publik & pencabutan

## Status
Accepted — 2026-11-21

## Context
Publik harus dapat memeriksa keaslian tanpa membuka isi dokumen yang rahasia.

## Decision
- QR berisi URL `https://<domain-verifikasi>/v/{public_id}`; `public_id` acak 128-bit (bukan ID berurutan).
- Halaman verifikasi menampilkan: status (VALID / DICABUT / DALAM PROSES), nomor & perihal
  dokumen (dapat disembunyikan per dokumen), nama + jabatan penanda tangan, waktu tanda tangan.
  Isi dokumen **tidak** ditampilkan.
- Verifikasi via upload: PDF divalidasi oleh `/documents/validate` dan hash dicocokkan dengan
  database. File tidak disimpan; batas 20 MB; rate limit per IP.
- Pencabutan dokumen: dicatat di Laravel (dengan alasan), ditampilkan di halaman verifikasi.
- Pencabutan sertifikat: lewat CRL Intermediate CA; URL CRL tercantum di sertifikat.

## Consequences
- Positif: verifikasi tanpa membuka isi dokumen; ID tidak bisa ditebak.
- Negatif: halaman verifikasi menjadi rujukan utama — saat downtime, verifikasi via QR gagal
  (tanda tangan di PDF tetap dapat divalidasi offline).
