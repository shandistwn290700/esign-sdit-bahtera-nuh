<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Signers\ManageSigner;
use App\Enums\SignerStepStatus;
use App\Enums\UserRole;
use App\Models\DocumentSigner;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin edits, deactivation and reactivation of accounts.
 *
 * Name, position, email and NIP are written into the signer's X.509 certificate subject and onto
 * every stamp. For a user with an active signing key they can only change together with a new
 * certificate (old one revoked as "superseded"), so stamp and certificate never disagree.
 */
final class ManageUserAccount
{
    /** Fields that appear in the certificate subject (signing-service services.py create_signer). */
    public const CERTIFICATE_FIELDS = ['name', 'position', 'email', 'nip'];

    public function __construct(
        private readonly ManageSigner $signers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, email: string, role: string, position?: string|null, nip?: string|null, phone?: string|null}  $data
     * @return bool whether a new certificate was issued
     */
    public function update(User $user, array $data, bool $reissueCertificate, User $admin, ?string $ip = null): bool
    {
        if ($user->is($admin) && $data['role'] !== UserRole::Admin->value) {
            throw ValidationException::withMessages(['role' => 'Anda tidak dapat mencabut peran admin dari akun sendiri.']);
        }

        $user->fill($data);
        $changes = $user->getDirty();
        if ($changes === []) {
            return false;
        }

        $identityChanged = array_intersect(array_keys($changes), self::CERTIFICATE_FIELDS) !== [];
        $reissue = $identityChanged && $user->hasSignerKey();
        if ($reissue && ! $reissueCertificate) {
            throw ValidationException::withMessages([
                'reissue_certificate' => 'Nama, jabatan, email, dan NIP tercantum di sertifikat. Centang "Terbitkan ulang sertifikat" untuk menyimpan perubahan ini.',
            ]);
        }

        $before = array_intersect_key($user->getOriginal(), $changes);
        DB::transaction(function () use ($user, $before, $changes, $admin, $ip): void {
            $user->save();
            $this->audit->record('user.updated', $user, ['before' => $before, 'after' => $changes], $admin, $ip);
        });

        if ($reissue) {
            // Revoke first: if issuing the new certificate fails, the user simply cannot sign
            // (admin retries "Aktifkan tanda tangan"); the old identity is never left valid.
            $this->signers->revoke($user, $admin, 'superseded', 'Data identitas diperbarui', $ip);
            $this->signers->enable($user, $admin, $ip);
        }

        return $reissue;
    }

    /** @return int documents currently waiting for this user's signature */
    public function deactivate(User $user, string $reason, bool $revokeCertificate, User $admin, ?string $ip = null): int
    {
        if ($user->is($admin)) {
            throw ValidationException::withMessages(['reason' => 'Anda tidak dapat menonaktifkan akun sendiri.']);
        }

        $pending = self::pendingSignatures($user);
        DB::transaction(function () use ($user, $reason, $pending, $admin, $ip): void {
            $user->forceFill(['is_active' => false])->save();
            // Database sessions: end every open session now instead of on the next request.
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            $this->audit->record('user.deactivated', $user, ['reason' => $reason, 'pending_signatures' => $pending], $admin, $ip);
        });

        if ($revokeCertificate && $user->hasSignerKey()) {
            $this->signers->revoke($user, $admin, 'affiliation_changed', $reason, $ip);
        }

        return $pending;
    }

    public function activate(User $user, User $admin, ?string $ip = null): void
    {
        $user->forceFill(['is_active' => true])->save();
        $this->audit->record('user.activated', $user, [], $admin, $ip);
    }

    public static function pendingSignatures(User $user): int
    {
        return DocumentSigner::query()
            ->where('user_id', $user->id)
            ->where('status', SignerStepStatus::Pending)
            ->count();
    }
}
