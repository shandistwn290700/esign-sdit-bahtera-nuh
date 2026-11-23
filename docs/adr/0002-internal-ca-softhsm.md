# ADR-0002: CA internal dua tingkat dengan SoftHSM2

## Status
Accepted — 2026-11-21

## Context
TTE kriptografis membutuhkan sertifikat per penanda tangan. Tanpa PSrE, instansi menjadi CA sendiri.

## Decision
- Root CA (ECDSA P-384) dibuat sekali di mesin offline, disimpan di dua media terenkripsi
  di lokasi berbeda. Tidak pernah ada di server.
- Intermediate CA dan kunci tiap penanda tangan dibuat dan disimpan di SoftHSM2 (PKCS#11)
  di dalam container Signing Service. Kunci ditandai non-extractable.
- Sertifikat signer berlaku 2 tahun; CRL diterbitkan oleh Intermediate CA.

## Alternatives Considered
- **Kunci terenkripsi di database** — sederhana, tapi kunci ikut terbaca jika DB dan app key bocor.
- **HSM fisik (YubiHSM 2)** — lebih aman, ada biaya perangkat. Jalur upgrade: sama-sama PKCS#11.
- **HashiCorp Vault/OpenBao transit** — bagus, tapi menambah satu sistem untuk dioperasikan.

## Consequences
- Positif: kunci tidak dapat diekspor lewat API; jalur upgrade ke HSM fisik jelas.
- Negatif: SoftHSM tetap software — keamanan bergantung pada isolasi container dan PIN.
  Volume token wajib dibackup terenkripsi.
