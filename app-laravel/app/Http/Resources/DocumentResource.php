<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Document;
use App\Models\DocumentSigner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Internal (authenticated) view of a document. The public verification page uses its own shape. */
final class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Document $doc */
        $doc = $this->resource;

        return [
            'public_id' => $doc->public_id,
            'title' => $doc->title,
            'number' => $doc->number,
            'subject' => $doc->subject,
            'show_metadata_publicly' => $doc->show_metadata_publicly,
            'status' => ['value' => $doc->status->value, 'label' => $doc->status->label()],
            'current_step' => $doc->current_step,
            'verify_url' => $doc->verifyUrl(),
            'creator' => $doc->relationLoaded('creator') ? ['id' => $doc->creator->id, 'name' => $doc->creator->name] : null,
            'signers' => $this->when($doc->relationLoaded('signers'), fn () => $doc->signers->map(fn (DocumentSigner $s): array => [
                'order' => $s->order,
                'user' => $s->relationLoaded('user') ? ['id' => $s->user->id, 'name' => $s->user->name, 'position' => $s->user->position] : null,
                'status' => $s->status->value,
                'page' => $s->page,
                'box' => ['x' => (float) $s->x, 'y' => (float) $s->y, 'width' => (float) $s->width, 'height' => (float) $s->height],
                'signed_at' => $s->signed_at?->toIso8601String(),
                'rejection_reason' => $s->rejection_reason,
            ])->all()),
            'created_at' => $doc->created_at?->toIso8601String(),
            'completed_at' => $doc->completed_at?->toIso8601String(),
            'revoked_at' => $doc->revoked_at?->toIso8601String(),
            'revocation_reason' => $doc->revocation_reason,
        ];
    }
}
