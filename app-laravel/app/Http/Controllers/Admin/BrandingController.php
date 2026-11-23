<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Admin page "Identitas aplikasi" plus the public logo endpoint. */
final class BrandingController extends Controller
{
    public function edit(Branding $branding): Response
    {
        return Inertia::render('admin/Branding', [
            'settings' => ['name' => $branding->name(), 'logo_url' => $branding->logoUrl()],
        ]);
    }

    public function update(Request $request, Branding $branding, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:3', 'max:60']]);
        $before = $branding->name();
        $branding->setName(trim($data['name']));

        /** @var User $admin */
        $admin = $request->user();
        $audit->record('branding.name_updated', null, ['before' => $before, 'after' => $branding->name()], $admin, $request->ip());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Nama aplikasi disimpan.']);

        return back();
    }

    public function uploadLogo(Request $request, Branding $branding, AuditLogger $audit): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'max:1024', 'mimes:png,jpg,jpeg,webp,svg', 'mimetypes:image/png,image/jpeg,image/webp,image/svg+xml'],
        ], attributes: ['logo' => 'logo']);

        $path = $branding->storeLogo($request->file('logo'));

        /** @var User $admin */
        $admin = $request->user();
        $audit->record('branding.logo_updated', null, ['path' => $path, 'sha256' => hash_file('sha256', Storage::disk('local')->path($path))], $admin, $request->ip());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Logo diperbarui.']);

        return back();
    }

    public function removeLogo(Request $request, Branding $branding, AuditLogger $audit): RedirectResponse
    {
        $branding->removeLogo();

        /** @var User $admin */
        $admin = $request->user();
        $audit->record('branding.logo_removed', null, [], $admin, $request->ip());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Logo dihapus. Logo bawaan dipakai kembali.']);

        return back();
    }

    /** Public: shown on the login and verification pages too. */
    public function logo(Branding $branding): HttpResponse
    {
        $path = $branding->all()['logo_path'];
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        $svg = str_ends_with($path, '.svg');

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => $svg ? 'image/svg+xml' : (Storage::disk('local')->mimeType($path) ?: 'application/octet-stream'),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            // An SVG opened directly (not via <img>) must not run scripts on this origin.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
