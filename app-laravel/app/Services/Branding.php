<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Institution identity shown in the UI: application name and logo.
 *
 * The logo lives on the private "local" disk and is served by BrandingController::logo with
 * hardening headers (an SVG opened directly must not run scripts on this origin).
 */
final class Branding
{
    public const DEFAULT_NAME = 'e-Sign SDIT Bahtera Nuh';

    private const CACHE_KEY = 'branding';

    private const DIR = 'branding';

    /** @return array{name: string, logo_path: string|null, logo_version: string|null} */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $rows = DB::table('app_settings')->whereIn('key', ['app_name', 'logo_path'])->get()->keyBy('key');
            $logo = $rows['logo_path'] ?? null;

            return [
                'name' => (string) ($rows['app_name']->value ?? self::DEFAULT_NAME),
                'logo_path' => $logo?->value,
                // Cache-busting token so browsers fetch a replaced logo immediately. Derived from
                // the random file name (not the timestamp, which has one-second resolution).
                'logo_version' => $logo ? substr(sha1((string) $logo->value), 0, 8) : null,
            ];
        });
    }

    public function name(): string
    {
        return $this->all()['name'];
    }

    public function logoUrl(): ?string
    {
        $all = $this->all();

        return $all['logo_path'] ? route('branding.logo', ['v' => $all['logo_version']]) : null;
    }

    public function setName(string $name): void
    {
        $this->put('app_name', $name);
    }

    public function storeLogo(UploadedFile $file): string
    {
        $old = $this->all()['logo_path'];
        $ext = strtolower($file->getClientOriginalExtension()) === 'svg' ? 'svg' : $file->extension();
        $path = $file->storeAs(self::DIR, 'logo-'.bin2hex(random_bytes(4)).'.'.$ext, 'local');
        $this->put('logo_path', $path);
        if ($old !== null && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return $path;
    }

    public function removeLogo(): void
    {
        $old = $this->all()['logo_path'];
        DB::table('app_settings')->where('key', 'logo_path')->delete();
        Cache::forget(self::CACHE_KEY);
        if ($old !== null) {
            Storage::disk('local')->delete($old);
        }
    }

    private function put(string $key, string $value): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget(self::CACHE_KEY);
    }
}
