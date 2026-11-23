<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'nameLocked' => self::nameLocked($user),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, AuditLogger $audit): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->fill($request->validated());

        // The name is printed on every signature stamp and the public verification page, so a
        // signer must not be able to rename themselves (e.g. to another official). Admin only.
        if ($user->isDirty('name') && self::nameLocked($user)) {
            throw ValidationException::withMessages([
                'name' => 'Nama penanda tangan hanya dapat diubah oleh admin.',
            ]);
        }

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $changes = array_intersect_key($user->getDirty(), array_flip(['name', 'email']));
        if ($changes !== []) {
            $before = array_intersect_key($user->getOriginal(), $changes);
            $user->save();
            $audit->record('user.profile_updated', $user, ['before' => $before, 'after' => $changes], $user, $request->ip());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    private static function nameLocked(User $user): bool
    {
        return $user->signer_key_id !== null;
    }
}
