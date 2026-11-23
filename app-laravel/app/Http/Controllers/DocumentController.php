<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Documents\RejectDocumentStep;
use App\Actions\Documents\RevokeDocument;
use App\Actions\Documents\SignDocumentStep;
use App\Actions\Documents\SubmitDocument;
use App\Enums\DocumentStatus;
use App\Enums\SignerStepStatus;
use App\Http\Requests\ReasonRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\TotpCodeRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentSigner;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $awaiting = DocumentSigner::query()
            ->where('user_id', $user->id)
            ->where('status', SignerStepStatus::Pending)
            ->with('document.creator')
            ->latest('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (DocumentSigner $s): Document => $s->document);

        $created = Document::query()
            ->where('creator_id', $user->id)
            ->with('signers.user', 'creator')
            ->latest()
            ->limit(100)
            ->get();

        return Inertia::render('documents/Index', [
            'awaiting' => DocumentResource::collection($awaiting),
            'created' => DocumentResource::collection($created),
            // Dashboard cards: counted in the database, not from the 100-row lists above.
            'stats' => [
                'awaiting' => DocumentSigner::query()->where('user_id', $user->id)->where('status', SignerStepStatus::Pending)->count(),
                'created_in_progress' => Document::query()->where('creator_id', $user->id)->where('status', DocumentStatus::InProgress)->count(),
                'created_completed' => Document::query()->where('creator_id', $user->id)->where('status', DocumentStatus::Completed)->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        $signers = User::query()
            ->where('is_active', true)
            ->whereNotNull('signer_key_id')
            ->whereNull('signer_revoked_at')
            ->whereNotNull('signer_totp_confirmed_at')
            ->orderBy('name')
            ->get(['id', 'name', 'position']);

        return Inertia::render('documents/Create', [
            'signers' => $signers,
            'maxUploadKb' => config('signer.max_upload_kb'),
        ]);
    }

    public function store(StoreDocumentRequest $request, SubmitDocument $submit): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{title: string, number?: string|null, subject?: string|null, show_metadata_publicly?: bool, signers: list<array{user_id: int, page: int, x: float, y: float, width: float, height: float}>} $data */
        $data = $request->safe()->except('file');

        $document = $submit->handle($user, $request->file('file')->get(), $data, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dokumen diajukan untuk ditandatangani.']);

        return to_route('documents.show', $document);
    }

    public function show(Request $request, Document $document): Response
    {
        Gate::authorize('view', $document);
        /** @var User $user */
        $user = $request->user();
        $document->load('signers.user', 'creator');
        $current = $document->currentSigner();

        return Inertia::render('documents/Show', [
            'document' => new DocumentResource($document),
            'can' => [
                'sign' => $current !== null && $current->user_id === $user->id,
                'revoke' => $user->can('revoke', $document),
                'update' => $user->can('update', $document),
                'delete' => $user->can('delete', $document),
            ],
        ]);
    }

    public function file(Request $request, Document $document, DocumentStorage $storage, AuditLogger $audit): HttpResponse
    {
        Gate::authorize('view', $document);
        $version = $document->latestVersion()->firstOrFail();
        $pdf = $storage->read($version);

        $download = $request->boolean('download');
        $audit->record('document.downloaded', $document, [
            'version_id' => $version->id,
            'sha256' => $version->sha256,
            'mode' => $download ? 'download' : 'view',
        ], $request->user(), $request->ip());

        $name = str($document->title)->slug()->limit(80, '')
            ->whenEmpty(fn ($s) => $s->append('dokumen'))
            ->append($document->status === DocumentStatus::Completed ? '-ditandatangani' : '')
            ->append('.pdf')->toString();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function sign(TotpCodeRequest $request, Document $document, SignDocumentStep $sign): RedirectResponse
    {
        Gate::authorize('view', $document);
        /** @var User $user */
        $user = $request->user();
        $sign->handle($document, $user, (string) $request->validated('totp_code'), $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dokumen berhasil ditandatangani.']);

        return back();
    }

    public function reject(ReasonRequest $request, Document $document, RejectDocumentStep $reject): RedirectResponse
    {
        Gate::authorize('view', $document);
        /** @var User $user */
        $user = $request->user();
        $reject->handle($document, $user, (string) $request->validated('reason'), $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dokumen ditolak dan dikembalikan ke pembuat.']);

        return back();
    }

    public function revoke(ReasonRequest $request, Document $document, RevokeDocument $revoke): RedirectResponse
    {
        Gate::authorize('revoke', $document);
        /** @var User $user */
        $user = $request->user();
        $revoke->handle($document, $user, (string) $request->validated('reason'), $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dokumen dicabut. Halaman verifikasi kini menampilkan status DICABUT.']);

        return back();
    }
}
