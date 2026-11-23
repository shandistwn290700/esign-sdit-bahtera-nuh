<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentVersionKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $document_id
 * @property DocumentVersionKind $kind
 * @property int|null $step_order
 * @property string $path
 * @property string $sha256
 * @property int $size
 * @property Carbon $created_at
 * @property-read Document $document
 */
#[Fillable(['document_id', 'kind', 'step_order', 'path', 'sha256', 'size'])]
final class DocumentVersion extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['kind' => DocumentVersionKind::class];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
