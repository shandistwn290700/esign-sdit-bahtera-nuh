<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class BrandingTest extends TestCase
{
    use RefreshDatabase;

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script><rect width="10" height="10"/></svg>';

    /** A real 1x1 PNG (the dev image has no GD for fake()->image()), optionally padded to a size. */
    private static function png(string $name, int $padTo = 0): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($name, str_pad($png, $padTo, "\0"));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_default_name_is_shared_with_every_page(): void
    {
        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('name', Branding::DEFAULT_NAME)
                ->where('branding.logo_url', null))
            ->assertSee('<meta name="app-name" content="'.Branding::DEFAULT_NAME.'">', false);
    }

    public function test_admin_renames_the_application(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('admin.branding.update'), ['name' => 'e-Sign Bahtera'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->get(route('admin.branding.edit'))->assertInertia(fn (Assert $page) => $page->where('name', 'e-Sign Bahtera'));
        $this->assertSame('e-Sign Bahtera', AuditLog::query()->where('action', 'branding.name_updated')->sole()->context['after']);
    }

    public function test_logo_upload_is_served_publicly_and_replacement_removes_the_old_file(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.branding.logo.store'), ['logo' => self::png('logo.png')])
            ->assertSessionHasNoErrors();
        $first = app(Branding::class)->all()['logo_path'];
        Storage::disk('local')->assertExists($first);

        $url = app(Branding::class)->logoUrl();
        $this->assertStringContainsString('/branding/logo?v=', (string) $url);
        auth()->logout();
        $response = $this->get($url);
        $response->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));

        $this->actingAs($admin)->post(route('admin.branding.logo.store'), ['logo' => self::png('baru.png')]);
        Storage::disk('local')->assertMissing($first);
        $this->assertNotSame($url, app(Branding::class)->logoUrl(), 'a new logo gets a new cache-busting URL');
    }

    public function test_svg_logo_is_sandboxed_when_opened_directly(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.branding.logo.store'), [
            'logo' => UploadedFile::fake()->createWithContent('logo.svg', self::SVG),
        ])->assertSessionHasNoErrors();

        $response = $this->get(app(Branding::class)->logoUrl());
        $response->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString('sandbox', $csp);
    }

    public function test_non_images_and_large_files_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.branding.logo.store'), [
            'logo' => UploadedFile::fake()->createWithContent('logo.php', '<?php echo 1;'),
        ])->assertSessionHasErrors('logo');
        $this->actingAs($admin)->post(route('admin.branding.logo.store'), [
            'logo' => self::png('besar.png', 1100 * 1024),
        ])->assertSessionHasErrors('logo');

        $this->assertNull(app(Branding::class)->logoUrl());
    }

    public function test_removing_the_logo_falls_back_to_the_default(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.branding.logo.store'), ['logo' => self::png('logo.png')]);
        $path = app(Branding::class)->all()['logo_path'];

        $this->actingAs($admin)->delete(route('admin.branding.logo.destroy'))->assertSessionHasNoErrors();

        $this->assertNull(app(Branding::class)->logoUrl());
        Storage::disk('local')->assertMissing($path);
        $this->get(route('branding.logo'))->assertNotFound();
    }

    public function test_staff_cannot_change_branding(): void
    {
        $staff = User::factory()->withTwoFactor()->create();

        $this->actingAs($staff)->get(route('admin.branding.edit'))->assertForbidden();
        $this->actingAs($staff)->put(route('admin.branding.update'), ['name' => 'Diretas'])->assertForbidden();
        $this->actingAs($staff)->post(route('admin.branding.logo.store'), ['logo' => self::png('x.png')])->assertForbidden();
    }
}
