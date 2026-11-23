<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SignatureController;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// Reachable before login 2FA is set up: this is where the user enrols it.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');
});

// Everything else requires an active account with login 2FA, like the document pages.
// Without this, a stolen password alone could activate a signer's signing authenticator.
Route::middleware(['auth', 'verified', EnsureTwoFactorEnabled::class])->group(function () {
    // Self-deletion is disabled: documents and the audit log must keep referencing their users.
    Route::redirect('settings', '/settings/profile');

    // Changing the email redirects password resets, so it needs a fresh password confirmation.
    Route::get('settings/profile', [ProfileController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->middleware([RequirePassword::class, 'throttle:6,1'])
        ->name('profile.update');

    Route::get('settings/signature', [SignatureController::class, 'edit'])->name('signature.edit');
    Route::post('settings/signature/activation', [SignatureController::class, 'start'])
        ->middleware([RequirePassword::class, 'throttle:6,1'])
        ->name('signature.activation.start');
    Route::post('settings/signature/activation/confirm', [SignatureController::class, 'confirm'])
        ->middleware('throttle:10,1')
        ->name('signature.activation.confirm');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
