<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\DocumentStatus;
use App\Enums\SignerStepStatus;
use App\Exceptions\DocumentStateException;
use App\Models\Document;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Marks a document as revoked (ADR-0006). The PDF's signatures stay cryptographically valid;
 * the verification page shows DICABUT. Certificate revocation is a separate, signer-level action.
 */
final class RevokeDocument
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Document $document, User $actor, string $reason, ?string $ip = null): Document
    {
        return DB::transaction(function () use ($document, $actor, $reason, $ip): Document {
            $doc = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($doc->status === DocumentStatus::Revoked) {
                throw new DocumentStateException('Dokumen sudah dicabut.');
            }

            $doc->signers()
                ->whereIn('status', [SignerStepStatus::Pending, SignerStepStatus::Waiting])
                ->update(['status' => SignerStepStatus::Skipped]);
            $doc->forceFill([
                'status' => DocumentStatus::Revoked,
                'current_step' => null,
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revocation_reason' => $reason,
            ])->save();

            $this->audit->record('document.revoked', $doc, ['reason' => $reason], $actor, $ip);

            return $doc;
        });
    }
}
