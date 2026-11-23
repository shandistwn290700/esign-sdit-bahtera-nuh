<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Documents\VerifyUploadedDocument;
use App\Enums\DocumentStatus;
use App\Enums\SignerStepStatus;
use App\Models\Document;
use App\Models\DocumentSigner;
use App\Services\Signer\SignerClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Public pages (ADR-0006). Show status and minimal metadata only; never document content.
 */
final class VerificationController extends Controller
{
    public function show(Document $document): Response
    {
        $document->load('signers');

        return Inertia::render('verify/Show', ['verification' => $this->publicView($document)]);
    }

    public function form(): Response
    {
        return Inertia::render('verify/Upload', ['result' => null]);
    }

    public function check(Request $request, VerifyUploadedDocument $verify): Response
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.config('signer.max_upload_kb')],
        ]);

        $result = $verify->handle($request->file('file')->get());
        $document = $result['document'];
        unset($result['document']);
        $result['verification'] = $document ? $this->publicView($document) : null;

        return Inertia::render('verify/Upload', ['result' => $result]);
    }

    public function crl(SignerClient $signer): HttpResponse
    {
        $der = Cache::remember('pki.crl', now()->addHour(), fn (): string => $signer->crlDer(SignerClient::newRequestId()));

        return response($der, 200, ['Content-Type' => 'application/pkix-crl', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function chain(SignerClient $signer): HttpResponse
    {
        $pem = Cache::remember('pki.chain', now()->addDay(), fn (): string => $signer->caChainPem(SignerClient::newRequestId()));

        return response($pem, 200, [
            'Content-Type' => 'application/x-pem-file',
            'Content-Disposition' => 'attachment; filename="ca-chain.pem"',
        ]);
    }

    /** @return array<string, mixed> */
    private function publicView(Document $document): array
    {
        $status = match ($document->status) {
            DocumentStatus::Completed => ['value' => 'valid', 'label' => 'VALID'],
            DocumentStatus::Revoked => ['value' => 'revoked', 'label' => 'DICABUT'],
            DocumentStatus::Rejected => ['value' => 'rejected', 'label' => 'DITOLAK'],
            DocumentStatus::InProgress => ['value' => 'in_progress', 'label' => 'DALAM PROSES'],
        };

        return [
            'public_id' => $document->public_id,
            'status' => $status,
            'title' => $document->show_metadata_publicly ? $document->title : null,
            'number' => $document->show_metadata_publicly ? $document->number : null,
            'signatures' => $document->signers
                ->where('status', SignerStepStatus::Signed)
                ->map(fn (DocumentSigner $s): array => [
                    'name' => $s->signer_name,
                    'position' => $s->signer_position,
                    'signed_at' => $s->signed_at?->toIso8601String(),
                ])->values()->all(),
            'signers_total' => $document->signers->count(),
            'completed_at' => $document->completed_at?->toIso8601String(),
            'revoked_at' => $document->revoked_at?->toIso8601String(),
        ];
    }
}
