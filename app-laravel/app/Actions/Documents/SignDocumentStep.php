<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\SignerStepStatus;
use App\Exceptions\DocumentStateException;
use App\Models\Document;
use App\Models\DocumentSigner;
use App\Models\User;
use App\Notifications\DocumentFinished;
use App\Notifications\DocumentSignedByYou;
use App\Notifications\SignatureRequested;
use App\Services\AuditLogger;
use App\Services\DocumentStorage;
use App\Services\Signer\SignerClient;
use App\Services\Signer\SignerException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The current signer signs their field. Runs synchronously so TOTP errors reach the form directly.
 * The document row is locked for the whole operation so two requests cannot sign the same step.
 * Never retried automatically: the TOTP code is single-use (docs/signing-contract.md).
 */
final class SignDocumentStep
{
    public function __construct(
        private readonly SignerClient $signer,
        private readonly DocumentStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws DocumentStateException|SignerException */
    public function handle(Document $document, User $user, string $totpCode, ?string $ip = null): DocumentSigner
    {
        $requestId = SignerClient::newRequestId();

        try {
            [$doc, $step, $next] = DB::transaction(
                fn (): array => $this->signLocked($document->id, $user, $totpCode, $requestId, $ip),
            );
        } catch (SignerException $e) {
            // Recorded outside the rolled-back transaction so failed attempts stay visible.
            $this->audit->record('document.sign_failed', $document, [
                'error_code' => $e->errorCode,
                'request_id' => $requestId,
            ], $user, $ip);
            throw $e;
        }

        $user->notify(new DocumentSignedByYou($doc, $step));
        if ($next !== null) {
            $next->user->notify(new SignatureRequested($doc));
        } else {
            $doc->creator->notify(new DocumentFinished($doc));
        }

        return $step;
    }

    /** @return array{0: Document, 1: DocumentSigner, 2: DocumentSigner|null} */
    private function signLocked(int $documentId, User $user, string $totpCode, string $requestId, ?string $ip): array
    {
        $doc = Document::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $doc->load(['signers.user', 'latestVersion', 'creator']);

        $step = $doc->currentSigner();
        if ($doc->status !== DocumentStatus::InProgress || $step === null) {
            throw new DocumentStateException('Dokumen ini tidak sedang menunggu tanda tangan.');
        }
        if ($step->user_id !== $user->id) {
            throw new DocumentStateException('Belum giliran Anda untuk menandatangani dokumen ini.');
        }
        if (! $user->canSign()) {
            throw new DocumentStateException('Tanda tangan Anda belum aktif. Aktifkan di Pengaturan > Tanda tangan.');
        }

        $latest = $doc->latestVersion ?? throw new RuntimeException("Document {$doc->id} has no version");
        $pdf = $this->storage->read($latest);

        $result = $this->signer->signDocument(
            pdf: $pdf,
            fieldName: $step->field_name,
            keyId: (string) $user->signer_key_id,
            totpCode: $totpCode,
            verifyUrl: $doc->verifyUrl(),
            certify: $step->order === 1,
            appearance: ['name' => $user->name, 'position' => $user->position ?: 'Pegawai', 'nip' => $user->nip],
            reason: config('signer.sign_reason'),
            location: config('signer.sign_location'),
            actor: $user,
            requestId: $requestId,
        );

        if (! hash_equals($latest->sha256, $result->inputSha256)) {
            throw new SignerException('input_hash_mismatch', 502, 'Signing Service signed a different input');
        }

        $version = $this->storage->store($doc, DocumentVersionKind::Signed, $result->pdf, $step->order);

        $step->forceFill([
            'status' => SignerStepStatus::Signed,
            'signed_at' => $result->signedAt,
            'certificate_serial' => $result->certificateSerial,
            'signer_name' => $user->name,
            'signer_position' => $user->position,
        ])->save();

        $next = $doc->signers->firstWhere('order', $step->order + 1);
        if ($next !== null) {
            $next->forceFill(['status' => SignerStepStatus::Pending])->save();
            $doc->current_step = $next->order;
        } else {
            $doc->forceFill([
                'status' => DocumentStatus::Completed,
                'current_step' => null,
                'completed_at' => now(),
            ]);
        }
        $doc->save();

        $this->audit->record('document.signed', $doc, [
            'step' => $step->order,
            'request_id' => $requestId,
            'input_sha256' => $result->inputSha256,
            'output_sha256' => $version->sha256,
            'certificate_serial' => $result->certificateSerial,
            'signed_at' => $result->signedAt->toIso8601String(),
            'completed' => $next === null,
        ], $user, $ip);

        return [$doc, $step, $next];
    }
}
