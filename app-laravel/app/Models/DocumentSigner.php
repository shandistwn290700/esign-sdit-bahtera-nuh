<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SignerStepStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $document_id
 * @property int $user_id
 * @property int $order
 * @property string $field_name
 * @property int $page
 * @property string $x
 * @property string $y
 * @property string $width
 * @property string $height
 * @property SignerStepStatus $status
 * @property string|null $signer_name
 * @property string|null $signer_position
 * @property string|null $certificate_serial
 * @property Carbon|null $signed_at
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property-read Document $document
 * @property-read User $user
 */
#[Fillable(['document_id', 'user_id', 'order', 'field_name', 'page', 'x', 'y', 'width', 'height', 'status'])]
final class DocumentSigner extends Model
{
    protected function casts(): array
    {
        return [
            'status' => SignerStepStatus::class,
            'signed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
