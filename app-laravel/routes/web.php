<?php

use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\DocumentController as AdminDocumentController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\RevokedDocumentController;
use App\Http\Controllers\VerificationController;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use Illuminate\Support\Facades\Route;

// Guests land on the login page via the auth middleware; the public check lives at /verifikasi.
Route::redirect('/', '/documents')->name('home');

// Public verification (ADR-0006). The QR on every stamp points to /v/{public_id}.
Route::get('v/{document:public_id}', [VerificationController::class, 'show'])
    ->where('document', '[0-9a-f]{32}')
    ->middleware('throttle:60,1')
    ->name('verify.show');
Route::get('verifikasi', [VerificationController::class, 'form'])->name('verify.form');
Route::post('verifikasi', [VerificationController::class, 'check'])
    ->middleware('throttle:10,1')
    ->name('verify.check');
Route::get('branding/logo', [BrandingController::class, 'logo'])->name('branding.logo');
Route::get('pki/crl', [VerificationController::class, 'crl'])->name('pki.crl');
Route::get('pki/ca-chain.pem', [VerificationController::class, 'chain'])->name('pki.chain');

Route::middleware(['auth', 'verified', EnsureTwoFactorEnabled::class])->group(function () {
    // Fortify's home; the document list is the real landing page.
    Route::redirect('dashboard', '/documents')->name('dashboard');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/create', [DocumentController::class, 'create'])->name('documents.create');
    Route::post('documents', [DocumentController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('documents.store');
    Route::get('documents/{document:public_id}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{document:public_id}/file', [DocumentController::class, 'file'])->name('documents.file');
    Route::post('documents/{document:public_id}/sign', [DocumentController::class, 'sign'])
        ->middleware('throttle:10,1')
        ->name('documents.sign');
    Route::post('documents/{document:public_id}/reject', [DocumentController::class, 'reject'])->name('documents.reject');
    Route::post('documents/{document:public_id}/revoke', [DocumentController::class, 'revoke'])->name('documents.revoke');
    // Only for revoked documents (DocumentPolicy::update/delete).
    Route::put('documents/{document:public_id}', [RevokedDocumentController::class, 'update'])->name('documents.update');
    Route::delete('documents/{document:public_id}', [RevokedDocumentController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('documents.destroy');

    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('documents', [AdminDocumentController::class, 'index'])->name('documents.index');
        Route::get('branding', [BrandingController::class, 'edit'])->name('branding.edit');
        Route::put('branding', [BrandingController::class, 'update'])->name('branding.update');
        Route::post('branding/logo', [BrandingController::class, 'uploadLogo'])
            ->middleware('throttle:10,1')
            ->name('branding.logo.store');
        Route::delete('branding/logo', [BrandingController::class, 'removeLogo'])->name('branding.logo.destroy');
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
        Route::post('users/{user}/signing', [UserController::class, 'enableSigning'])->name('users.signing.enable');
        Route::post('users/{user}/signing/reset-authenticator', [UserController::class, 'resetAuthenticator'])->name('users.signing.reset');
        Route::post('users/{user}/signing/revoke', [UserController::class, 'revokeSigning'])->name('users.signing.revoke');
    });
});

require __DIR__.'/settings.php';
