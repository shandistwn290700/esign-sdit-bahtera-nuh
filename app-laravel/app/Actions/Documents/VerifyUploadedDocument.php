<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\DocumentVersionKind;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\Signer\SignerClient;

/**
 * Public "upload to verify" (ADR-0006): cryptographic validation by the Signing Service plus a
 * lookup of the exact file in our records. The uploaded file is never stored.
 */
final class VerifyUploadedDocument
{
    public function __construct(private readonly SignerClient $signer) {}

    /**
     * @return array{
     *     signature_status: string,
     *     modified_after_last_signature: bool,
     *     signatures: list<array<string, mixed>>,
     *     registered: bool,
     *     is_latest_version: bool,
     *     document: Document|null,
     * }
     */
    public function handle(string $pdf): array
    {
        $report = $this->signer->validateDocument($pdf, SignerClient::newRequestId());

        $version = DocumentVersion::query()
            ->where('sha256', hash('sha256', $pdf))
            ->where('kind', DocumentVersionKind::Signed)
            ->with('document.signers', 'document.latestVersion')
            ->first();

        $document = $version?->document;

        /** @var list<array<string, mixed>> $signatures */
        $signatures = array_map(fn (array $s): array => [
            'signer_name' => $s['signer_name'] ?? null,
            'signed_at' => $s['signed_at'] ?? null,
            'valid' => (bool) ($s['valid'] ?? false),
            'revoked' => (bool) ($s['revoked'] ?? false),
            'problems' => $s['problems'] ?? [],
        ], $report['signatures'] ?? []);

        return [
            'signature_status' => (string) ($report['status'] ?? 'invalid'),
            'modified_after_last_signature' => (bool) ($report['modified_after_last_signature'] ?? false),
            'signatures' => $signatures,
            'registered' => $document !== null,
            'is_latest_version' => $document !== null && $document->latestVersion?->is($version) === true,
            'document' => $document,
        ];
    }
}
