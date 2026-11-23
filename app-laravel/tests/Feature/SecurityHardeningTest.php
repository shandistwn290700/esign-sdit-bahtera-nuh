<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Regression tests for the pre-go-live security review (docs/security-review.md). */
final class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_signer_cannot_rename_themselves(): void
    {
        $signer = User::factory()->signer()->create(['name' => 'Budi Santoso']);

        $this->actingAs($signer)->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('profile.update'), ['name' => 'Kepala Dinas', 'email' => $signer->email])
            ->assertSessionHasErrors(['name' => 'Nama penanda tangan hanya dapat diubah oleh admin.']);

        $this->assertSame('Budi Santoso', $signer->fresh()->name);
    }

    public function test_non_signer_profile_change_is_audited(): void
    {
        $user = User::factory()->withTwoFactor()->create(['name' => 'Rina']);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('profile.update'), ['name' => 'Rina Staf', 'email' => $user->email])
            ->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('action', 'user.profile_updated')->sole();
        $this->assertSame(['name' => 'Rina'], $log->context['before']);
        $this->assertSame(['name' => 'Rina Staf'], $log->context['after']);
    }

    public function test_profile_change_requires_password_confirmation(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('password.confirm'));
        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'X', 'email' => 'attacker@example.com'])
            ->assertRedirect(route('password.confirm'));
        $this->assertNotSame('attacker@example.com', $user->fresh()->email);
    }

    public function test_signature_activation_requires_login_two_factor(): void
    {
        // Password only (no login 2FA yet): must not be able to bind a signing authenticator.
        $user = User::factory()->signer()->create([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'signer_totp_confirmed_at' => null,
        ]);

        $this->actingAs($user)->get(route('signature.edit'))->assertRedirect(route('security.edit'));
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('signature.activation.start'))
            ->assertRedirect(route('security.edit'));
        $this->actingAs($user)->post(route('signature.activation.confirm'), ['totp_code' => '123456'])
            ->assertRedirect(route('security.edit'));
    }

    public function test_deactivated_account_cannot_log_in(): void
    {
        $user = User::factory()->withTwoFactor()->create(['is_active' => false]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Email atau kata sandi salah.']);
        $this->assertGuest();
    }

    public function test_deactivated_session_is_ended_on_settings_pages(): void
    {
        $user = User::factory()->withTwoFactor()->create(['is_active' => false]);

        $this->actingAs($user)->get(route('signature.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_security_headers_and_csp_nonce(): void
    {
        $response = $this->get(route('login'));

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $csp);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $m);
        $this->assertStringContainsString('<script nonce="'.$m[1].'">', $response->getContent());
    }

    public function test_appearance_cookie_is_allow_listed(): void
    {
        $response = $this->withUnencryptedCookie('appearance', "x';alert(1);//")->get(route('login'));

        $this->assertStringNotContainsString('alert(1)', $response->getContent());
        $this->assertStringContainsString("const appearance = 'system';", $response->getContent());
    }
}
