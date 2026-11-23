<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $title
 * @property string|null $number
 * @property string|null $subject
 * @property bool $show_metadata_publicly
 * @property int $creator_id
 * @property DocumentStatus $status
 * @property int|null $current_step
 * @property Carbon|null $completed_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property string|null $revocation_reason
 * @property-read User $creator
 * @property-read Collection<int, DocumentSigner> $signers
 * @property-read Collection<int, DocumentVersion> $versions
 * @property-read DocumentVersion|null $latestVersion
 */
#[Fillable(['public_id', 'title', 'number', 'subject', 'show_metadata_publicly', 'creator_id', 'status', 'current_step'])]
final class Document extends Model
{
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'show_metadata_publicly' => 'boolean',
            'completed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function newPublicId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /** @return HasMany<DocumentSigner, $this> */
    public function signers(): HasMany
    {
        return $this->hasMany(DocumentSigner::class)->orderBy('order');
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('id');
    }

    /** @return HasOne<DocumentVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->latestOfMany();
    }

    public function currentSigner(): ?DocumentSigner
    {
        if ($this->status !== DocumentStatus::InProgress || $this->current_step === null) {
            return null;
        }

        return $this->signers->firstWhere('order', $this->current_step);
    }

    public function verifyUrl(): string
    {
        return config('signer.verify_base_url').'/'.$this->public_id;
    }

    public function involves(User $user): bool
    {
        return $this->creator_id === $user->id
            || $this->signers->contains('user_id', $user->id);
    }
}
