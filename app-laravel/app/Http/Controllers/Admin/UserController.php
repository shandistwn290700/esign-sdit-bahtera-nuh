<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Signers\ManageSigner;
use App\Actions\Users\ManageUserAccount;
use App\Enums\SignerStepStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReasonRequest;
use App\Models\DocumentSigner;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin-only user and signer management. Public registration is disabled. */
final class UserController extends Controller
{
    public function index(): Response
    {
        $pending = DocumentSigner::query()
            ->where('status', SignerStepStatus::Pending)
            ->groupBy('user_id')
            ->selectRaw('user_id, count(*) as total')
            ->pluck('total', 'user_id');

        $users = User::query()->orderBy('name')->get()->map(fn (User $u): array => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role->value,
            'position' => $u->position,
            'nip' => $u->nip,
            'phone' => $u->phone,
            'is_active' => $u->is_active,
            'pending_signatures' => (int) ($pending[$u->id] ?? 0),
            'two_factor_enabled' => $u->two_factor_confirmed_at !== null,
            'signer' => [
                'enabled' => $u->hasSignerKey(),
                'activated' => $u->signer_totp_confirmed_at !== null,
                'revoked' => $u->signer_revoked_at !== null,
            ],
        ]);

        return Inertia::render('admin/Users', ['users' => $users]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'position' => ['nullable', 'string', 'max:128'],
            'nip' => ['nullable', 'digits:18'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $user = new User($data + ['password' => Str::password(32)]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Password::sendResetLink(['email' => $user->email]);

        /** @var User $admin */
        $admin = $request->user();
        $audit->record('user.created', $user, ['email' => $user->email, 'role' => $user->role->value], $admin, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Akun dibuat. Tautan untuk membuat password dikirim ke {$user->email}."]);

        return back();
    }

    public function update(Request $request, User $user, ManageUserAccount $accounts): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'position' => ['nullable', 'string', 'max:128'],
            'nip' => ['nullable', 'digits:18'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);
        $data['email'] = Str::lower($data['email']);
        /** @var User $admin */
        $admin = $request->user();

        $reissued = $accounts->update($user, $data, $request->boolean('reissue_certificate'), $admin, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => $reissued
            ? "Data {$user->name} disimpan dan sertifikat baru diterbitkan. Minta yang bersangkutan mengaktifkan ulang autentikator tanda tangannya."
            : "Data {$user->name} disimpan."]);

        return back();
    }

    public function deactivate(ReasonRequest $request, User $user, ManageUserAccount $accounts): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $pending = $accounts->deactivate($user, (string) $request->validated('reason'), $request->boolean('revoke_certificate'), $admin, $request->ip());

        $message = "Akun {$user->name} dinonaktifkan.";
        if ($pending > 0) {
            $message .= " {$pending} dokumen sedang menunggu tanda tangannya: pembuat dokumen perlu mencabut dan mengajukan ulang.";
        }
        Inertia::flash('toast', ['type' => $pending > 0 ? 'warning' : 'success', 'message' => $message]);

        return back();
    }

    public function activate(Request $request, User $user, ManageUserAccount $accounts): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $accounts->activate($user, $admin, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Akun {$user->name} diaktifkan kembali."]);

        return back();
    }

    public function enableSigning(Request $request, User $user, ManageSigner $signers): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $signers->enable($user, $admin, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Tanda tangan diaktifkan untuk {$user->name}. Minta yang bersangkutan mengaktifkan autentikatornya."]);

        return back();
    }

    public function resetAuthenticator(ReasonRequest $request, User $user, ManageSigner $signers): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $signers->resetAuthenticator($user, $admin, (string) $request->validated('reason'), $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Autentikator tanda tangan {$user->name} direset."]);

        return back();
    }

    public function revokeSigning(Request $request, User $user, ManageSigner $signers): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(['unspecified', 'key_compromise', 'affiliation_changed', 'superseded', 'cessation_of_operation'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        /** @var User $admin */
        $admin = $request->user();
        $signers->revoke($user, $admin, $data['reason'], $data['note'] ?? null, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sertifikat tanda tangan {$user->name} dicabut."]);

        return back();
    }
}
