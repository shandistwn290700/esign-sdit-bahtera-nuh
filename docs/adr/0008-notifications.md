# ADR-0008: Notifikasi hanya berisi tautan

## Status
Accepted — 2026-11-21. **Amended 2026-11-23:** WhatsApp ditunda atas keputusan instansi.

## Amendment 2026-11-23
- Saat ini notifikasi **hanya email**: permintaan tanda tangan, dokumen selesai, dan tanda tangan
  tercatat.
- Kolom nomor WhatsApp pada data pengguna tetap disimpan agar WhatsApp bisa ditambahkan tanpa
  migrasi data.
- Queue memakai `queue:work` biasa (service `worker` di `compose.prod.yml`), bukan Horizon.
- Bila WhatsApp dihidupkan kembali, keputusan di bawah (Cloud API resmi, template disetujui Meta,
  isi hanya tautan) tetap berlaku.

## Decision
- Email via SMTP (akun instansi atau layanan email transaksional).
- WhatsApp via **WhatsApp Cloud API resmi** dengan template pesan yang disetujui Meta.
- Isi notifikasi: judul singkat + tautan ke aplikasi (butuh login). Tidak pernah melampirkan PDF.
- Dikirim lewat queue (Horizon) dengan retry.

## Alternatives Considered
- **Gateway WA tidak resmi** — murah, tapi berisiko nomor diblokir dan pesan melewati pihak ketiga.

## Consequences
- Negatif: Cloud API butuh verifikasi bisnis Meta dan ada biaya per percakapan.
