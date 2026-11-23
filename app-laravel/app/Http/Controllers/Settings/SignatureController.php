<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Signers\ManageSigner;
use App\Http\Controllers\Controller;
use App\Http\Requests\TotpCodeRequest;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signer's own page to activate the signing authenticator (separate from login 2FA).
 * The secret is shown once via an (encrypted) one-request session flash and never stored here.
 */
final class SignatureController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/Signature', [
            'signature' => [
                'enabled' => $user->hasSignerKey(),
                'activated' => $user->signer_totp_confirmed_at !== null,
                'revoked' => $user->signer_revoked_at !== null,
                'certificate_serial' => $user->signer_certificate_serial,
                'name' => $user->name,
                'position' => $user->position,
                'nip' => $user->nip,
            ],
            'activation' => $request->session()->get('signer_activation'),
        ]);
    }

    public function start(Request $request, ManageSigner $signers): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $uri = $signers->startActivation($user, $request->ip());

        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($uri);

        return back()->with('signer_activation', [
            'qr_svg' => $svg,
            'manual_key' => is_string($query['secret'] ?? null) ? $query['secret'] : null,
        ]);
    }

    public function confirm(TotpCodeRequest $request, ManageSigner $signers): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $signers->confirmActivation($user, (string) $request->validated('totp_code'), $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Autentikator tanda tangan aktif. Anda sekarang dapat menandatangani dokumen.']);

        return back();
    }
}
