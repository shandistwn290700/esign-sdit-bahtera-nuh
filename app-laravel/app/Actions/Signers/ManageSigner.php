<?php

declare(strict_types=1);

namespace App\Actions\Signers;

use App\Exceptions\DocumentStateException;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Signer\SignerClient;

/**
 * Signer lifecycle (ADR-0002/0003).
 *
 * The admin enables signing (key + certificate are created in the HSM) but never sees the TOTP
 * secret: the signer starts activation on their own settings page, which issues a fresh secret.
 */
final class ManageSigner
{
    public function __construct(
        private readonly SignerClient $signer,
        private readonly AuditLogger $audit,
    ) {}

    public function enable(User $user, User $admin, ?string $ip = null): User
    {
        if ($user->hasSignerKey()) {
            throw new DocumentStateException('Pengguna ini sudah memiliki kunci tanda tangan aktif.');
        }
        $requestId = SignerClient::newRequestId();
        // The otpauth URI returned here is deliberately discarded (admin must not see it).
        $created = $this->signer->createSigner($user, $admin, $requestId);

        $user->forceFill([
            'signer_key_id' => $created['key_id'],
            'signer_certificate_serial' => $created['certificate']['serial'] ?? null,
            'signer_totp_confirmed_at' => null,
            'signer_revoked_at' => null,
        ])->save();

        $this->audit->record('signer.enabled', $user, [
            'key_id' => $created['key_id'],
            'certificate_serial' => $user->signer_certificate_serial,
            'request_id' => $requestId,
        ], $admin, $ip);

        return $user;
    }

    /** Returns the otpauth:// URI to render as a QR code once. Never store or log it. */
    public function startActivation(User $user, ?string $ip = null): string
    {
        if (! $user->hasSignerKey()) {
            throw new DocumentStateException('Tanda tangan belum diaktifkan untuk akun Anda. Hubungi admin.');
        }
        if ($user->signer_totp_confirmed_at !== null) {
            throw new DocumentStateException('Autentikator tanda tangan sudah aktif. Minta admin untuk meresetnya.');
        }
        $requestId = SignerClient::newRequestId();
        $uri = $this->signer->resetTotp((string) $user->signer_key_id, $user, 'Aktivasi oleh pemilik akun', $requestId);

        $this->audit->record('signer.activation_started', $user, ['request_id' => $requestId], $user, $ip);

        return $uri;
    }

    public function confirmActivation(User $user, string $code, ?string $ip = null): User
    {
        if (! $user->hasSignerKey()) {
            throw new DocumentStateException('Tanda tangan belum diaktifkan untuk akun Anda.');
        }
        $requestId = SignerClient::newRequestId();
        $this->signer->confirmTotp((string) $user->signer_key_id, $code, $requestId);

        $user->forceFill(['signer_totp_confirmed_at' => now()])->save();
        $this->audit->record('signer.activated', $user, ['request_id' => $requestId], $user, $ip);

        return $user;
    }

    /** Admin: invalidate the signer's authenticator (lost phone). The signer re-activates. */
    public function resetAuthenticator(User $user, User $admin, string $reason, ?string $ip = null): User
    {
        if (! $user->hasSignerKey()) {
            throw new DocumentStateException('Pengguna ini tidak memiliki kunci tanda tangan aktif.');
        }
        $requestId = SignerClient::newRequestId();
        $this->signer->resetTotp((string) $user->signer_key_id, $admin, $reason, $requestId);

        $user->forceFill(['signer_totp_confirmed_at' => null])->save();
        $this->audit->record('signer.authenticator_reset', $user, ['reason' => $reason, 'request_id' => $requestId], $admin, $ip);

        return $user;
    }

    public function revoke(User $user, User $admin, string $reason, ?string $note = null, ?string $ip = null): User
    {
        if (! $user->hasSignerKey()) {
            throw new DocumentStateException('Pengguna ini tidak memiliki kunci tanda tangan aktif.');
        }
        $requestId = SignerClient::newRequestId();
        $this->signer->revokeSigner((string) $user->signer_key_id, $reason, $admin, $requestId, $note);

        $user->forceFill(['signer_revoked_at' => now(), 'signer_totp_confirmed_at' => null])->save();
        $this->audit->record('signer.revoked', $user, [
            'key_id' => $user->signer_key_id,
            'reason' => $reason,
            'request_id' => $requestId,
        ], $admin, $ip);

        return $user;
    }
}
