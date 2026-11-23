<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\SignerStepStatus;
use App\Models\Document;
use App\Models\User;
use App\Notifications\SignatureRequested;
use App\Services\AuditLogger;
use App\Services\DocumentStorage;
use App\Services\Signer\SignerClient;
use App\Services\Signer\SignerException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Upload + signer order + QR placement -> prepared PDF with every signature field (ADR-0005).
 */
final class SubmitDocument
{
    public function __construct(
        private readonly SignerClient $signer,
        private readonly DocumentStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{title: string, number?: string|null, subject?: string|null, show_metadata_publicly?: bool,
     *     signers: list<array{user_id: int, page: int, x: float, y: float, width: float, height: float}>}  $data
     */
    public function handle(User $creator, string $pdf, array $data, ?string $ip = null): Document
    {
        $users = User::query()->whereIn('id', array_column($data['signers'], 'user_id'))->get()->keyBy('id');
        foreach ($data['signers'] as $i => $row) {
            $user = $users->get($row['user_id']);
            if ($user === null || ! $user->canSign()) {
                throw ValidationException::withMessages([
                    "signers.{$i}.user_id" => 'Pengguna ini belum dapat menandatangani (tanda tangan belum diaktifkan).',
                ]);
            }
        }

        $fields = [];
        foreach (array_values($data['signers']) as $i => $row) {
            $fields[] = [
                'name' => 'sig_'.($i + 1),
                'order' => $i + 1,
                'page' => (int) $row['page'],
                'x' => (float) $row['x'],
                'y' => (float) $row['y'],
                'width' => (float) $row['width'],
                'height' => (float) $row['height'],
            ];
        }

        $requestId = SignerClient::newRequestId();
        try {
            $prepared = $this->signer->prepareDocument($pdf, $fields, $requestId);
        } catch (SignerException $e) {
            $key = in_array($e->errorCode, ['field_out_of_bounds', 'field_overlap', 'page_not_found'], true) ? 'signers' : 'file';
            throw ValidationException::withMessages([$key => $e->userMessage()]);
        }

        $document = DB::transaction(function () use ($creator, $pdf, $prepared, $data, $fields, $requestId, $ip): Document {
            $document = Document::query()->create([
                'public_id' => Document::newPublicId(),
                'title' => $data['title'],
                'number' => $data['number'] ?? null,
                'subject' => $data['subject'] ?? null,
                'show_metadata_publicly' => $data['show_metadata_publicly'] ?? true,
                'creator_id' => $creator->id,
                'status' => DocumentStatus::InProgress,
                'current_step' => 1,
            ]);

            $original = $this->storage->store($document, DocumentVersionKind::Original, $pdf);
            $preparedVersion = $this->storage->store($document, DocumentVersionKind::Prepared, $prepared->pdf);

            foreach ($fields as $i => $field) {
                $document->signers()->create([
                    'user_id' => $data['signers'][$i]['user_id'],
                    'order' => $field['order'],
                    'field_name' => $field['name'],
                    'page' => $field['page'],
                    'x' => $field['x'],
                    'y' => $field['y'],
                    'width' => $field['width'],
                    'height' => $field['height'],
                    'status' => $field['order'] === 1 ? SignerStepStatus::Pending : SignerStepStatus::Waiting,
                ]);
            }

            $this->audit->record('document.submitted', $document, [
                'title' => $document->title,
                'signer_user_ids' => array_column($data['signers'], 'user_id'),
                'original_sha256' => $original->sha256,
                'prepared_sha256' => $preparedVersion->sha256,
                'request_id' => $requestId,
            ], $creator, $ip);

            return $document;
        });

        $document->load('signers.user');
        $document->currentSigner()?->user->notify(new SignatureRequested($document));

        return $document;
    }
}
