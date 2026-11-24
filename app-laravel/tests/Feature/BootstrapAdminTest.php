<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class BootstrapAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('TTE_ADMIN_EMAIL');
        putenv('TTE_ADMIN_PASSWORD');
        parent::tearDown();
    }

    private function env(string $email, string $password): void
    {
        putenv("TTE_ADMIN_EMAIL={$email}");
        putenv("TTE_ADMIN_PASSWORD={$password}");
    }

    public function test_creates_the_first_admin_from_environment(): void
    {
        $this->env(' Admin@Example.org ', 'Kuat-Sekali-2026');

        $this->artisan('tte:bootstrap-admin')->assertSuccessful()
            ->doesntExpectOutputToContain('Kuat-Sekali-2026');

        $admin = User::query()->where('email', 'admin@example.org')->sole();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('Kuat-Sekali-2026', $admin->password));
        $this->assertNull($admin->two_factor_confirmed_at, '2FA is still enrolled at first login');
        $this->assertSame('bootstrap', AuditLog::query()->where('action', 'user.created')->sole()->context['via']);
    }

    public function test_is_a_no_op_once_an_admin_exists(): void
    {
        User::factory()->admin()->create(['email' => 'first@example.org']);
        $this->env('second@example.org', 'Kuat-Sekali-2026');

        $this->artisan('tte:bootstrap-admin')->assertSuccessful();

        $this->assertFalse(User::query()->where('email', 'second@example.org')->exists());
    }

    public function test_skips_without_variables_and_rejects_weak_passwords(): void
    {
        $this->artisan('tte:bootstrap-admin')->assertSuccessful();
        $this->assertSame(0, User::query()->count());

        $this->env('admin@example.org', 'pendek');
        $this->artisan('tte:bootstrap-admin')->assertFailed()
            ->doesntExpectOutputToContain('pendek');
        $this->assertSame(0, User::query()->count());
    }
}
