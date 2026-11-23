<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string|null $uuid
 * @property UserRole $role
 * @property string|null $position
 * @property string|null $nip
 * @property string|null $phone
 * @property bool $is_active
 * @property string|null $signer_key_id
 * @property string|null $signer_certificate_serial
 * @property Carbon|null $signer_totp_confirmed_at
 * @property Carbon|null $signer_revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'position', 'nip', 'phone', 'is_active'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'signer_totp_confirmed_at' => 'datetime',
            'signer_revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (User $user): void {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** Has a key in the Signing Service (may still need TOTP activation). */
    public function hasSignerKey(): bool
    {
        return $this->signer_key_id !== null && $this->signer_revoked_at === null;
    }

    /** Can be assigned as a signer and can sign right now. */
    public function canSign(): bool
    {
        return $this->is_active && $this->hasSignerKey() && $this->signer_totp_confirmed_at !== null;
    }
}
