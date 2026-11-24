<?php

declare(strict_types=1);

namespace App\Services\Signer;

use RuntimeException;
use Throwable;

/**
 * A problem+json error from the Signing Service, or a transport failure (`signer_unreachable`).
 * `code` values are the stable codes from signing-service/openapi.yaml.
 */
final class SignerException extends RuntimeException
{
    /** Codes the person at the keyboard can fix (shown next to the TOTP field). */
    private const USER_FIXABLE = ['totp_invalid', 'totp_replayed', 'totp_locked'];

    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly ?string $detail = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Signing Service error {$status} {$errorCode}".($detail ? ": {$detail}" : ''), 0, $previous);
    }

    public function isUserFixable(): bool
    {
        return in_array($this->errorCode, self::USER_FIXABLE, true);
    }

    /** User-facing Indonesian message (docs/signing-contract.md). Never expose `detail` publicly. */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'totp_invalid' => 'Kode salah. Periksa jam di HP Anda dan coba lagi.',
            'totp_replayed' => 'Kode sudah dipakai. Tunggu kode berikutnya.',
            'totp_locked' => 'Terlalu banyak percobaan. Coba lagi dalam '.max(1, (int) ceil(($this->retryAfter ?? 60) / 60)).' menit.',
            'signer_totp_pending' => 'Aktifkan dulu aplikasi autentikator tanda tangan Anda.',
            'signer_revoked' => 'Sertifikat tanda tangan Anda tidak aktif. Hubungi admin.',
            'field_already_signed', 'previous_field_unsigned', 'field_not_found' => 'Status dokumen berubah. Muat ulang halaman.',
            'input_hash_mismatch', 'pdf_modified_after_signature' => 'Dokumen tidak konsisten. Hubungi admin.',
            'pdf_invalid', 'pdf_encrypted' => 'PDF tidak dapat diproses. Pastikan PDF tidak berpassword dan tidak rusak.',
            'pdf_already_signed' => 'PDF ini sudah mengandung tanda tangan digital lain.',
            'pdf_too_large' => 'Ukuran PDF melebihi batas 20 MB.',
            'field_out_of_bounds' => 'Kotak QR berada di luar halaman.',
            'field_overlap' => 'Kotak QR saling tumpang tindih.',
            'page_not_found' => 'Halaman untuk kotak QR tidak ada di PDF.',
            // Infrastructure: say what is wrong and who can fix it, instead of a generic "gangguan".
            'ca_not_initialized' => 'Sertifikat CA tanda tangan belum dipasang di server. Hubungi admin untuk menjalankan prosedur Root CA.',
            'hsm_unavailable' => 'Modul kunci tanda tangan tidak dapat diakses. Coba lagi beberapa saat; bila berulang, hubungi admin.',
            'tsa_unavailable' => 'Server stempel waktu tidak dapat dihubungi. Coba lagi beberapa saat.',
            'signer_unreachable' => 'Layanan tanda tangan tidak dapat dihubungi. Coba lagi beberapa saat; bila berulang, hubungi admin.',
            default => 'Layanan tanda tangan sedang gangguan. Coba lagi beberapa saat.',
        };
    }
}
