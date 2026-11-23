<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\DocumentStatus;
use App\Exceptions\DocumentStateException;
use App\Models\Document;
use App\Models\DocumentSigner;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Update and hard delete for REVOKED documents only.
 *
 * Only metadata can change: the signed PDF is never touched. Deletion removes the document, its
 * versions, its signer steps and the encrypted files. The audit log is append-only and has no
 * foreign key to documents, so its history (including a snapshot written here) survives.
 * After deletion the public QR page answers 404 instead of "DICABUT" (the admin's choice).
 */
final class ManageRevokedDocument
{
    public const EDITABLE = ['title', 'number', 'subject', 'show_metadata_publicly'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function update(Document $document, array $data, User $actor, ?string $ip = null): Document
    {
        return DB::transaction(function () use ($document, $data, $actor, $ip): Document {
            $doc = $this->lockRevoked($document);
            $doc->fill(array_intersect_key($data, array_flip(self::EDITABLE)));
            $changes = $doc->getDirty();
            if ($changes === []) {
                return $doc;
            }
            $before = array_intersect_key($doc->getOriginal(), $changes);
            $doc->save();
            $this->audit->record('document.updated', $doc, ['before' => $before, 'after' => $changes], $actor, $ip);

            return $doc;
        });
    }

    public function delete(Document $document, User $actor, ?string $ip = null): void
    {
        $publicId = DB::transaction(function () use ($document, $actor, $ip): string {
            $doc = $this->lockRevoked($document);
            $doc->load('signers', 'versions');

            // Snapshot first: after this transaction the rows are gone, the audit entry is not.
            $this->audit->record('document.deleted', $doc, [
                'public_id' => $doc->public_id,
                'title' => $doc->title,
                'number' => $doc->number,
                'creator_id' => $doc->creator_id,
                'revoked_at' => $doc->revoked_at?->toIso8601String(),
                'revocation_reason' => $doc->revocation_reason,
                'signers' => $doc->signers->map(fn (DocumentSigner $s): array => [
                    'order' => $s->order,
                    'user_id' => $s->user_id,
                    'name' => $s->signer_name,
                    'status' => $s->status->value,
                    'signed_at' => $s->signed_at?->toIso8601String(),
                    'certificate_serial' => $s->certificate_serial,
                ])->all(),
                'versions' => $doc->versions->map(fn (DocumentVersion $v): array => [
                    'kind' => $v->kind->value,
                    'sha256' => $v->sha256,
                ])->all(),
            ], $actor, $ip);

            DocumentSigner::query()->where('document_id', $doc->id)->delete();
            DocumentVersion::query()->where('document_id', $doc->id)->delete();
            $doc->delete();

            return $doc->public_id;
        });

        // Files go only after the rows are committed, so a failed transaction never loses a PDF.
        Storage::disk('documents')->deleteDirectory($publicId);
    }

    private function lockRevoked(Document $document): Document
    {
        $doc = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
        if ($doc->status !== DocumentStatus::Revoked) {
            throw new DocumentStateException('Hanya dokumen yang sudah dicabut yang dapat diubah atau dihapus.');
        }

        return $doc;
    }
}
