# Backup dan Pemulihan

**Untuk:** admin IT e-Sign SDIT Bahtera Nuh.

## Apa yang dicadangkan

Setiap malam pukul 02.00 WIB, service `backup` membuat **satu berkas** `esign-YYYYmmdd-HHMMSS.tar.age`. Isinya:

| Isi | Keterangan |
|---|---|
| `database.dump` | Seluruh database: pengguna, dokumen, audit log |
| `storage.tar` | PDF (sudah terenkripsi AES-256-GCM) dan logo |
| `signer/signer.sqlite3` | Data Signing Service: sertifikat, status pencabutan, rahasia TOTP terenkripsi |
| `signer/softhsm.tar` | Token SoftHSM, berisi kunci CA perantara dan kunci penanda tangan (terkunci PIN HSM) |
| `MANIFEST.sha256` | Sidik jari setiap berkas, untuk memastikan tidak ada yang rusak |

Berkas itu dienkripsi dengan **kunci publik `age`**. Server bisa membuat backup, tapi **tidak bisa membukanya**. Jadi pembobol server tidak bisa membaca backup lama, dan ransomware tidak bisa memalsukannya tanpa ketahuan.

Backup disimpan 14 hari di volume `backups`. Kalau `BACKUP_RCLONE_REMOTE` diisi, setiap backup juga disalin ke penyimpanan luar (Google Drive, S3, dan sebagainya).

### Yang TIDAK ada di dalam backup (simpan sendiri, offline)

Tanpa nilai-nilai ini, backup tidak bisa dipulihkan:

- `DOCUMENT_ENCRYPTION_KEY`, untuk membuka PDF,
- `SIGNER_HSM_PIN` dan `SIGNER_HSM_SO_PIN`, untuk memakai kunci di token SoftHSM,
- `SIGNER_TOTP_ENCRYPTION_KEY`, untuk rahasia autentikator penanda tangan,
- `APP_KEY`, `DB_PASSWORD`, dan `SIGNER_API_TOKEN` (bisa dibuat ulang, tapi lebih mudah kalau disimpan),
- **kunci privat `age`** (lihat di bawah).

Simpan semuanya di amplop tersegel atau pengelola kata sandi milik sekolah, di dua tempat terpisah.

## Persiapan sekali: kunci age

Lakukan di komputer admin, bukan di server:

```sh
age-keygen -o esign-backup.key
```

- Isi baris `# public key: age1…` ke variabel `BACKUP_AGE_RECIPIENT` di Coolify.
- Berkas `esign-backup.key` adalah **kunci privat**: simpan di flashdisk terenkripsi bersama cadangan Root CA, jangan di server.

Program `age` tersedia untuk Windows, macOS, dan Linux (https://age-encryption.org).

## Salinan off-site (disarankan)

Backup yang hanya ada di VPS yang sama ikut hilang kalau VPS rusak. Salinan off-site dibuat dengan `rclone`. Konfigurasinya diisi lewat environment variable Coolify, tanpa berkas config. Contoh untuk penyimpanan S3/R2:

```
BACKUP_RCLONE_REMOTE=offsite:esign-backup
RCLONE_CONFIG_OFFSITE_TYPE=s3
RCLONE_CONFIG_OFFSITE_PROVIDER=Cloudflare
RCLONE_CONFIG_OFFSITE_ACCESS_KEY_ID=...
RCLONE_CONFIG_OFFSITE_SECRET_ACCESS_KEY=...
RCLONE_CONFIG_OFFSITE_ENDPOINT=https://<akun>.r2.cloudflarestorage.com
```

Variabel `RCLONE_CONFIG_*` perlu ditambahkan ke bagian `environment` service `backup` di `compose.prod.yml`. Kalau penyalinan off-site gagal, salinan lokal tetap ada dan log mencatat `ERROR off-site copy`.

## Memeriksa backup berjalan

Di Coolify, buka service `backup → Logs`. Setiap malam harus ada baris `backup …: done`.

Untuk menjalankan backup sekarang juga, buka terminal service `backup`:

```sh
backup-entrypoint.sh now
```

## Pemulihan

Contoh di bawah memulihkan ke server baru (misalnya VPS pengganti) yang sudah di-deploy dengan `compose.prod.yml` dan **rahasia yang sama**.

### 1. Buka dan periksa backup (di komputer admin)

```sh
age --decrypt -i esign-backup.key -o esign.tar esign-20261124-020000.tar.age
mkdir pulih && tar -C pulih -xf esign.tar
cd pulih && sha256sum -c MANIFEST.sha256
```

Semua baris harus `OK`. Kalau ada yang `FAILED`, pakai backup hari sebelumnya.

### 2. Hentikan aplikasi

Di Coolify, *Stop* service `web`, `worker`, `scheduler`, dan `signer`. Biarkan `postgres` tetap jalan.

### 3. Pulihkan database

Salin `database.dump` ke container postgres, lalu:

```sh
pg_restore --clean --if-exists --no-owner -U esign -d esign /tmp/database.dump
```

Trigger *append-only* audit log ikut terpulihkan, sehingga audit log tetap tidak bisa diubah.

### 4. Pulihkan berkas

Isi volume `app-storage` dengan `storage.tar`. Untuk volume `signer-data`, ekstrak `softhsm.tar` dan salin `signer.sqlite3` ke dalamnya. Pemilik berkas harus sama dengan user container:

- `www-data` untuk `app-storage`,
- `signer` untuk `signer-data`.

Contoh memakai container sementara:

```sh
docker run --rm -v <proyek>_app-storage:/dst -v "$PWD:/src" alpine \
  sh -c 'tar -C /dst -xf /src/storage.tar && chown -R 33:33 /dst'
docker run --rm -v <proyek>_signer-data:/dst -v "$PWD:/src" alpine \
  sh -c 'tar -C /dst -xf /src/signer/softhsm.tar && cp /src/signer/signer.sqlite3 /dst/ && chown -R $(stat -c %u:%g /dst) /dst'
```

### 5. Jalankan dan periksa

1. *Start* semua service.
2. Di terminal `web`, jalankan `php artisan audit:verify`. Hasilnya harus menyatakan rantai audit log utuh.
3. Login, buka satu dokumen lama, lalu **Unduh PDF**. Kalau PDF-nya terbuka, berarti `DOCUMENT_ENCRYPTION_KEY` benar.
4. Pindai QR dokumen lama, lalu tandatangani dokumen uji. Kalau keduanya berhasil, kunci HSM dan PIN-nya benar.

## Uji restore berkala

[ADR-0009](../adr/0009-deploy-coolify.md) mewajibkan uji restore **setiap 3 bulan**:

1. Pulihkan backup terbaru ke VPS uji atau komputer lokal mengikuti langkah di atas.
2. Catat tanggal, nama berkas backup, dan hasil langkah 5.

Backup yang belum pernah dicoba dipulihkan belum bisa dianggap backup.
