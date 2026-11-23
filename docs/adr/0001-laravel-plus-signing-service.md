# ADR-0001: Laravel monolit + Signing Service Python terisolasi

## Status
Accepted — 2026-11-21

## Context
Tim memilih PHP Laravel. Dukungan PAdES di PHP terbatas (TCPDF minim fitur; SetaPDF-Signer
berbayar). Aplikasi bersifat rahasia sehingga kunci privat sebaiknya terpisah dari aplikasi web.

## Decision
Seluruh logika bisnis di Laravel (monolit). Operasi kriptografi (buat kunci, prepare, sign,
validate, CRL) di layanan terpisah berbasis Python + FastAPI + pyHanko, hanya dapat diakses
dari jaringan Docker internal.

## Alternatives Considered
- **Laravel + SetaPDF-Signer** — satu bahasa, tapi lisensi komersial dan kunci diakses proses web.
- **Sidecar Java (iText/PDFBox)** — matang, tapi lebih berat; iText berlisensi AGPL/komersial.
- **Microservices penuh** — berlebihan untuk satu instansi.

## Consequences
- Positif: kunci terisolasi; library PAdES terlengkap dan gratis.
- Negatif: dua bahasa; butuh satu orang yang menguasai Python.

## Trade-offs
Isolasi kunci dan kelengkapan PAdES diprioritaskan di atas keseragaman bahasa.
