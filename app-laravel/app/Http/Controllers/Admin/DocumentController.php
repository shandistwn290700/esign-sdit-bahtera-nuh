<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin-only list of every document in the institution, with search and status filter. */
final class DocumentController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
        ]);
        $q = trim((string) ($filters['q'] ?? ''));
        $status = $filters['status'] ?? null;

        $search = function (Builder $query) use ($q): void {
            if ($q === '') {
                return;
            }
            // Escape LIKE wildcards so "%" or "_" typed by the admin match literally.
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn (Builder $w) => $w
                ->whereLike('title', $like)
                ->orWhereLike('number', $like)
                ->orWhereHas('creator', fn (Builder $c) => $c->whereLike('name', $like)));
        };

        $documents = Document::query()
            ->tap($search)
            ->when($status, fn (Builder $query, string $s) => $query->where('status', $s))
            ->with(['creator', 'signers.user'])
            ->latest()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Counts per status for the filter tabs, respecting the current search.
        $counts = Document::query()->tap($search)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('admin/Documents', [
            'documents' => DocumentResource::collection($documents->getCollection()),
            'pagination' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'total' => $documents->total(),
                'from' => $documents->firstItem(),
                'to' => $documents->lastItem(),
                'prev_url' => $documents->previousPageUrl(),
                'next_url' => $documents->nextPageUrl(),
            ],
            'filters' => ['q' => $q, 'status' => $status],
            'counts' => collect(DocumentStatus::cases())->mapWithKeys(fn (DocumentStatus $s): array => [
                $s->value => ['label' => $s->label(), 'total' => (int) ($counts[$s->value] ?? 0)],
            ]),
        ]);
    }
}
