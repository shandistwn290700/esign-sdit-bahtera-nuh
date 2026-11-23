<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Documents\ManageRevokedDocument;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/** Update/delete of revoked documents (creator or admin). Everything else stays in DocumentController. */
final class RevokedDocumentController extends Controller
{
    public function update(Request $request, Document $document, ManageRevokedDocument $manage): RedirectResponse
    {
        Gate::authorize('update', $document);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'number' => ['nullable', 'string', 'max:100'],
            'subject' => ['nullable', 'string', 'max:255'],
            'show_metadata_publicly' => ['boolean'],
        ]);
        $data['show_metadata_publicly'] = $request->boolean('show_metadata_publicly');

        /** @var User $user */
        $user = $request->user();
        $manage->update($document, $data, $user, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Keterangan dokumen disimpan.']);

        return back();
    }

    public function destroy(Request $request, Document $document, ManageRevokedDocument $manage): RedirectResponse
    {
        Gate::authorize('delete', $document);
        // Typed confirmation: deletion is permanent and the printed QR will no longer resolve.
        $request->validate(['confirmation' => ['required', 'in:HAPUS']], [
            'confirmation.in' => 'Ketik HAPUS (huruf kapital) untuk mengonfirmasi.',
        ], ['confirmation' => 'konfirmasi']);

        /** @var User $user */
        $user = $request->user();
        $title = $document->title;
        $manage->delete($document, $user, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Dokumen \"{$title}\" dihapus permanen."]);

        return to_route('documents.index');
    }
}
