# Prosedur Root CA Offline

**Untuk:** admin IT yang menyiapkan server produksi e-Sign SDIT Bahtera Nuh, disaksikan Kepala Sekolah.
**Kapan:** sekali, sebelum sertifikat tanda tangan pertama diterbitkan. Selanjutnya hanya saat CA perantara perlu diperpanjang (lihat bagian akhir).
**Lama:** sekitar 1 jam.

Semua perintah di dokumen ini sudah diuji coba dengan SoftHSM dan OpenSSL sungguhan (2026-11-23).

## Gambaran singkat

Ada dua kunci CA, dan keduanya tidak pernah berada di tempat yang sama:

| | Root CA | CA perantara |
|---|---|---|
| Tempat kunci | Laptop **offline**, lalu disimpan di 2 flashdisk terenkripsi | HSM di server (tidak bisa disalin keluar) |
| Tugas | Menandatangani CA perantara, sekali per 5 tahun | Menerbitkan sertifikat penanda tangan setiap hari |
| Masa berlaku | 15 tahun | 5 tahun |

Kalau server dibobol, Root tetap aman. Sekolah cukup menerbitkan CA perantara baru tanpa mengganti Root.

Yang berpindah antara server dan laptop offline hanyalah berkas **publik**: CSR (permintaan sertifikat) dan dua sertifikat. Kunci rahasia tidak pernah berpindah.

## Yang perlu disiapkan

- **Laptop offline.** Boot dari USB Linux live (misalnya Ubuntu), Wi-Fi dan kabel jaringan dicabut sejak awal. Paket `openssl` sudah termasuk di Ubuntu.
- **3 flashdisk:**
  - **A:** pemindah berkas publik.
  - **B** dan **C:** cadangan kunci Root, terenkripsi (LUKS atau VeraCrypt).
- **Passphrase kunci Root:** minimal 5 kata acak. Tulis di kertas, masukkan ke 2 amplop tersegel:
  - satu di brankas sekolah,
  - satu di yayasan.
- **Berkas [`root-ca.cnf`](root-ca.cnf)** dari repositori, disalin ke flashdisk A.
- **Formulir berita acara** (contoh di bagian akhir), untuk mencatat sidik jari.

Pastikan baris `O = SDIT Bahtera Nuh` di `root-ca.cnf` sama persis dengan `SIGNER_ORG_NAME` di server.

## Langkah 1 — Server: buat CSR CA perantara

Signing Service harus sudah berjalan dengan:
- `SIGNER_APP_ENV=production`,
- `SIGNER_ORG_NAME="SDIT Bahtera Nuh"`.

Di server, cari nama container signer, lalu buat CSR:

```sh
docker ps --filter name=signer --format '{{.Names}}'
docker exec <container-signer> tte-signer ca-csr > intermediate.csr
```

Perintah ini membuat kunci CA perantara **di dalam HSM**, lalu mencetak CSR-nya. Kalau dijalankan lagi sebelum langkah 4, kunci yang sama dipakai ulang, jadi aman diulang.

Salin `intermediate.csr` ke flashdisk A.

## Langkah 2 — Laptop offline: buat kunci dan sertifikat Root

Salin `root-ca.cnf` dan `intermediate.csr` dari flashdisk A ke folder kerja, misalnya `~/ca`. Lalu jalankan:

```sh
cd ~/ca
openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-384 -aes-256-cbc -out root.key
openssl req -new -x509 -config root-ca.cnf -key root.key -days 5479 -out root.pem
openssl x509 -in root.pem -noout -subject -enddate -fingerprint -sha256
```

- Perintah pertama meminta passphrase. Ketik passphrase dari amplop.
- Perintah kedua meminta passphrase yang sama.
- **Catat sidik jari SHA-256 Root di berita acara.**

## Langkah 3 — Laptop offline: periksa dan tandatangani CSR

```sh
openssl req -in intermediate.csr -noout -verify -subject
```

Pastikan hasilnya `verify OK` dan subject-nya `C = ID, O = SDIT Bahtera Nuh, CN = SDIT Bahtera Nuh Signing CA`. Kalau berbeda, **berhenti**: CSR itu bukan dari server Anda.

```sh
openssl x509 -req -in intermediate.csr -CA root.pem -CAkey root.key \
  -CAcreateserial -CAserial root.srl -days 1826 -sha384 \
  -extfile root-ca.cnf -extensions v3_intermediate -out intermediate.pem
openssl verify -CAfile root.pem intermediate.pem
openssl x509 -in intermediate.pem -noout -fingerprint -sha256
```

- `openssl verify` harus menjawab `intermediate.pem: OK`.
- **Catat sidik jari CA perantara di berita acara.**

Salin **hanya** `root.pem` dan `intermediate.pem` ke flashdisk A. Jangan pernah menyalin `root.key` ke flashdisk A.

## Langkah 4 — Server: pasang sertifikat

```sh
docker cp root.pem <container-signer>:/tmp/root.pem
docker cp intermediate.pem <container-signer>:/tmp/intermediate.pem
docker exec <container-signer> tte-signer ca-import --root /tmp/root.pem --intermediate /tmp/intermediate.pem
```

Sebelum memasang, server memeriksa bahwa:
- sertifikat CA perantara memang ditandatangani Root ini,
- kuncinya sama dengan kunci di HSM,
- batasannya benar (`pathlen:0`),
- masa berlakunya tidak melebihi Root.

Setelah itu server mencetak dua sidik jari. **Cocokkan keduanya dengan berita acara.** Kalau ada yang berbeda, jangan dipakai dan hubungi pengembang.

Terakhir, **restart service signer** (di Coolify: tombol *Restart* pada service signer). Tanpa restart, proses yang sedang berjalan belum melihat kunci baru di HSM.

Uji dengan mengaktifkan tanda tangan satu pengguna di menu **Pengguna**. Kalau berhasil, CA sudah bekerja.

## Langkah 5 — Simpan kunci Root dan bersihkan laptop

1. Salin `root.key`, `root.pem`, `root.srl`, dan `root-ca.cnf` ke flashdisk **B** dan **C** yang terenkripsi.
2. Simpan B di brankas sekolah dan C di yayasan, terpisah dari amplop passphrase.
3. Matikan laptop. Karena bootnya dari USB live, tidak ada yang tersimpan. Kalau memakai laptop biasa, hapus folder `~/ca` secara aman.
4. Tanda tangani berita acara: admin IT dan Kepala Sekolah.

## Membagikan Root ke pihak luar

Agar Adobe Reader di komputer lain menampilkan tanda tangan sebagai "tepercaya", pengguna mengimpor sertifikat Root ke Adobe: *Preferences → Signatures → Identities & Trusted Certificates*.

Rantai sertifikat bisa diunduh di `https://<domain>/pki/ca-chain.pem`. Umumkan sidik jari Root di situs atau surat resmi sekolah, agar orang bisa memastikan berkas yang mereka unduh asli.

## Kondisi darurat

| Kejadian | Akibat | Tindakan |
|---|---|---|
| Server dibobol atau HSM bocor | Kunci CA perantara dianggap tidak aman | Hentikan signer. Buat CA perantara baru dengan Root yang sama (perlu perintah tambahan, lihat di bawah). Terbitkan ulang semua sertifikat penanda tangan. |
| Flashdisk B hilang | Kunci Root terenkripsi, tapi tetap berisiko | Pakai C untuk membuat salinan baru. Pertimbangkan membuat Root baru. |
| B, C, atau passphrase hilang semua | Tidak bisa menerbitkan CA perantara baru | Server tetap berjalan sampai CA perantara habis masa berlakunya. Sebelum itu, buat Root baru dan bagikan ulang sidik jarinya. |
| Laptop offline sempat terhubung internet saat kunci ada di dalamnya | Kunci Root mungkin bocor | Buat ulang Root sebelum dipakai. |

## Perpanjangan dan batas yang belum didukung

- **Sertifikat penanda tangan** berlaku 2 tahun (`SIGNER_SIGNER_CERT_DAYS=730`).
  - Sebelum ada yang kedaluwarsa, aplikasi perlu dukungan **LTV**, agar tanda tangan lama tetap bisa diverifikasi setelah sertifikatnya habis.
  - Ini tercatat sebagai pekerjaan lanjutan.
- **CA perantara** berlaku 5 tahun.
  - Saat ini `ca-import` menolak memasang CA kedua.
  - Penggantian CA perantara (karena perpanjangan atau server dibobol) memerlukan perintah tambahan yang belum dibuat.
  - Jadwalkan pembuatannya minimal 6 bulan sebelum tanggal kedaluwarsa di berita acara.

## Contoh berita acara

```
BERITA ACARA PEMBUATAN ROOT CA — e-Sign SDIT Bahtera Nuh

Tanggal/jam      : ____________________   Tempat: ____________________
Laptop offline   : ____________________   (jaringan diputus: ya / tidak)

Root CA
  Subject        : C=ID, O=SDIT Bahtera Nuh, CN=SDIT Bahtera Nuh Root CA
  Berlaku sampai : ____________________
  SHA-256        : __:__:__:__:__:__:__:__:__:__:__:__:__:__:__:__
                   __:__:__:__:__:__:__:__:__:__:__:__:__:__:__:__

CA perantara
  Subject        : C=ID, O=SDIT Bahtera Nuh, CN=SDIT Bahtera Nuh Signing CA
  Berlaku sampai : ____________________
  SHA-256        : (sama formatnya)
  Cocok dengan keluaran server (ca-import): ya / tidak

Penyimpanan kunci Root
  Flashdisk B    : brankas sekolah        Flashdisk C: yayasan
  Amplop passphrase: brankas sekolah, yayasan

Admin IT                         Kepala Sekolah
(__________________)             (__________________)
```
