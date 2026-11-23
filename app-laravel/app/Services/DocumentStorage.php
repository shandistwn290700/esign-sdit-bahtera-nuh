<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DocumentVersionKind;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Stores every PDF version encrypted at rest on the private `documents` disk (ADR-0007, amended).
 * The SHA-256 of the plaintext is stored in the database and re-checked on every read.
 */
final class DocumentStorage
{
    public function __construct(private readonly Encrypter $encrypter) {}

    public function store(Document $document, DocumentVersionKind $kind, string $pdf, ?int $stepOrder = null): DocumentVersion
    {
        $sha = hash('sha256', $pdf);
        $path = sprintf('%s/%s-%s-%s.pdf.enc', $document->public_id, $kind->value, $stepOrder ?? 0, substr($sha, 0, 16));

        if (! $this->disk()->put($path, $this->encrypter->encryptString($pdf))) {
            throw new RuntimeException("Could not write {$path}");
        }

        return $document->versions()->create([
            'kind' => $kind,
            'step_order' => $stepOrder,
            'path' => $path,
            'sha256' => $sha,
            'size' => strlen($pdf),
        ]);
    }

    public function read(DocumentVersion $version): string
    {
        $encrypted = $this->disk()->get($version->path);
        if ($encrypted === null) {
            throw new RuntimeException("Missing stored document {$version->path}");
        }
        $pdf = $this->encrypter->decryptString($encrypted);

        if (! hash_equals($version->sha256, hash('sha256', $pdf))) {
            throw new RuntimeException("Integrity check failed for document version {$version->id}");
        }

        return $pdf;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('documents');
    }
}
