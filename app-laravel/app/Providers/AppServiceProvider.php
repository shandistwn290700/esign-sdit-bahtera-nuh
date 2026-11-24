<?php

namespace App\Providers;

use App\Models\User;
use App\Services\DocumentStorage;
use App\Services\Signer\SignerClient;
use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SignerClient::class, fn (): SignerClient => SignerClient::fromConfig());

        $this->app->singleton(DocumentStorage::class, function (): DocumentStorage {
            $key = (string) config('signer.document_key');
            if ($key === '') {
                throw new RuntimeException('DOCUMENT_ENCRYPTION_KEY is not set');
            }
            $raw = Str::startsWith($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

            return new DocumentStorage(new Encrypter((string) $raw, 'aes-256-gcm'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('admin', fn (User $user): bool => $user->isAdmin() && $user->is_active);

        // The URL is printed permanently in every QR and the Signing Service rejects non-https
        // values, so a wrong setting must stop deployment instead of failing every signature.
        if (app()->isProduction() && ! str_starts_with((string) config('signer.verify_base_url'), 'https://')) {
            throw new RuntimeException('VERIFY_BASE_URL must start with https:// in production.');
        }
        // Session cookies carry a signer's authenticated session; never send them over plain http.
        if (app()->isProduction() && config('session.secure') !== true) {
            throw new RuntimeException('SESSION_SECURE_COOKIE must be true in production.');
        }
        // Behind Cloudflare -> Traefik the forwarded proto may not survive every hop; the public
        // site is https-only, so generated URLs (assets, redirects, signed links) must be https.
        if (app()->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Resources are only used as Inertia props here; the "data" wrapper would hide fields.
        JsonResource::withoutWrapping();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
