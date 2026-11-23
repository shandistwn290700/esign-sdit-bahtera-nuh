<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\DocumentStatus;
use App\Enums\SignerStepStatus;
use App\Exceptions\DocumentStateException;
use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentFinished;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/** The current signer declines; the document goes back to its creator. */
final class RejectDocumentStep
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Document $document, User $user, string $reason, ?string $ip = null): Document
    {
        $doc = DB::transaction(function () use ($document, $user, $reason, $ip): Document {
            $doc = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $doc->load('signers', 'creator');

            $step = $doc->currentSigner();
            if ($step === null || $step->user_id !== $user->id) {
                throw new DocumentStateException('Belum giliran Anda untuk dokumen ini.');
            }

            $step->forceFill([
                'status' => SignerStepStatus::Rejected,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();
            $doc->signers()->where('order', '>', $step->order)->update(['status' => SignerStepStatus::Skipped]);
            $doc->forceFill([
                'status' => DocumentStatus::Rejected,
                'current_step' => null,
                'rejected_at' => now(),
            ])->save();

            $this->audit->record('document.rejected', $doc, ['step' => $step->order, 'reason' => $reason], $user, $ip);

            return $doc;
        });

        $doc->creator->notify(new DocumentFinished($doc));

        return $doc;
    }
}
