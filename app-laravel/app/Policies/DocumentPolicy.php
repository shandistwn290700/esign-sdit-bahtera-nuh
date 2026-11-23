<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;

final class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $user->isAdmin() || $document->involves($user);
    }

    /** Metadata edits are only allowed once a document is revoked (its signatures no longer count). */
    public function update(User $user, Document $document): bool
    {
        return $document->status === DocumentStatus::Revoked
            && ($user->isAdmin() || $document->creator_id === $user->id);
    }

    /** Permanent deletion, likewise only for revoked documents. */
    public function delete(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }

    public function revoke(User $user, Document $document): bool
    {
        return $document->status !== DocumentStatus::Revoked
            && ($user->isAdmin() || $document->creator_id === $user->id);
    }
}
