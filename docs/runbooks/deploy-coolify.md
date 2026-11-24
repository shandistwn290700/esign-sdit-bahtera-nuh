# Deploy ke Coolify

**Untuk:** admin IT yang memasang e-Sign SDIT Bahtera Nuh di VPS yang dikelola Coolify.
**Berkas:** [`compose.prod.yml`](../../compose.prod.yml) di root repositori.

## Isi stack

| Service | Fungsi | Domain |
|---|---|---|
| `web` | Laravel (Nginx + PHP-FPM, port 80). Menjalankan migrasi saat start. | **Ya**, satu-satunya |
| `worker` | Antrean (email notifikasi) | Tidak |
| `scheduler` | Tugas terjadwal (cek dan jangkar audit log harian) | Tidak |
| `signer` | Signing Service + SoftHSM | **Tidak boleh** |
| `postgres` | Database | Tidak |
| `redis` | Antrean dan cache | Tidak |
| `backup` | Backup terenkripsi harian, pukul 02.00 WIB | Tidak |

Tidak ada service yang membuka port ke host. Yang bisa diakses dari internet hanya `web`, lewat proxy Coolify yang sekaligus mengurus HTTPS.

## 1. Siapkan repositori

Coolify mengambil kode dari repositori git, misalnya GitHub atau GitLab privat.

1. Push repositori ini ke remote **privat**.
2. Di Coolify: **Project → New Resource → Docker Compose** (dari repositori privat). Pilih branch `main`, lalu isi *Docker Compose Location* dengan `/compose.prod.yml`.

## 2. Buat rahasia

Buat semua nilai ini di komputer admin, lalu isikan ke **Environment Variables** di Coolify. Untuk variabel yang tidak boleh terlihat di log, centang *Is Secret*.

| Variabel | Cara membuat | Catatan |
|---|---|---|
| `APP_URL` | contoh `https://esign.sditbahteranuh.sch.id` | Tanpa `/` di akhir. Tercetak permanen di setiap QR, jadi **jangan diganti** setelah ada dokumen. |
| `APP_KEY` | `echo "base64:$(openssl rand -base64 32)"` | |
| `DOCUMENT_ENCRYPTION_KEY` | `echo "base64:$(openssl rand -base64 32)"` | **Hilang = semua PDF tidak bisa dibuka.** Simpan salinannya offline. |
| `DB_PASSWORD` | `openssl rand -base64 24` | |
| `SIGNER_API_TOKEN` | `openssl rand -hex 32` | Dipakai bersama oleh `web` dan `signer` |
| `SIGNER_HSM_PIN` | `openssl rand -hex 8` | **Hilang = kunci HSM tidak bisa dipakai.** Simpan offline. |
| `SIGNER_HSM_SO_PIN` | `openssl rand -hex 8` | Simpan offline |
| `SIGNER_TOTP_ENCRYPTION_KEY` | `openssl rand -base64 32` | Hilang = semua penanda tangan harus mengaktifkan ulang autentikator |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | dari penyedia email | Gmail/Workspace: `smtp.gmail.com`, port 587, pakai *App Password* |
| `BACKUP_AGE_RECIPIENT` | lihat [backup-restore.md](backup-restore.md) | Kunci **publik** `age1…` |
| `BACKUP_RCLONE_REMOTE` (opsional) | lihat backup-restore.md | Salinan off-site |
| `SIGNER_SIGN_LOCATION` (opsional) | contoh `Bekasi` | Tercetak di tanda tangan PDF |

Contoh lengkapnya ada di [`.env.production.example`](../../.env.production.example).

Lima nilai bertanda **simpan offline** di tabel di atas ikut ditulis di kertas yang disimpan di amplop tersegel, sama seperti passphrase Root CA. Kelima nilai itu **tidak** ikut di dalam backup.

## 3. Domain

Di Coolify, buka service **web → Domains**, isi `https://esign.sditbahteranuh.sch.id` (tanpa port: container `web` mendengarkan di port 80). Arahkan DNS domain ke IP VPS.

Service lain **tidak** diberi domain.

## 4. Deploy pertama

1. Klik **Deploy**. Build pertama memakan waktu sekitar 5–10 menit.
2. Tunggu sampai service berstatus *healthy*. `web` otomatis menjalankan migrasi database.
   **`signer` akan tetap *unhealthy* sampai CA dipasang di langkah 3.** Itu memang disengaja:
   sebelum ada CA, Signing Service menolak bekerja dan aplikasi menampilkan pesan gangguan saat
   tanda tangan diaktifkan.
3. Siapkan CA tanda tangan mengikuti [root-ca-offline.md](root-ca-offline.md), langkah 1–5.
4. Buat akun admin pertama di terminal service `web` (Coolify → web → *Terminal*):
   ```sh
   php artisan tte:create-admin
   ```
5. Login, aktifkan 2FA, lalu unggah logo di **Identitas aplikasi**.

## 5. Periksa setelah deploy

- [ ] `https://<domain>/login` terbuka dengan gembok HTTPS.
- [ ] Header respons memuat `Strict-Transport-Security` dan `Content-Security-Policy`.
- [ ] Email reset kata sandi benar-benar terkirim ke kotak masuk.
- [ ] Alur lengkap berjalan: aktifkan tanda tangan satu pengguna, ajukan dokumen uji, tandatangani, lalu pindai QR-nya dari HP.
- [ ] Service `backup` mencatat `backup …: done`. Jalankan sekali secara manual di terminal service backup dengan `backup-entrypoint.sh now`.
- [ ] Uji restore pertama, sesuai [backup-restore.md](backup-restore.md).

## Pembaruan aplikasi

Push ke branch `main`, lalu **Redeploy** di Coolify. Migrasi berjalan otomatis.

Volume (`app-storage`, `signer-data`, `pg-data`) tidak tersentuh saat redeploy. **Jangan** menekan *Delete volumes* di Coolify: menghapus `signer-data` berarti kunci HSM hilang.

## Batas yang perlu diketahui

- Aplikasi berjalan di satu server, jadi kalau VPS mati, aplikasi ikut mati. Pemulihannya lewat backup.
- Notifikasi WhatsApp ditunda ([ADR-0008](../adr/0008-notifications.md)); notifikasi hanya lewat email.
