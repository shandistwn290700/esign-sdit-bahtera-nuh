<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeSigningService;
use Tests\TestCase;

final class SignerManagementTest extends TestCase
{
    use RefreshDatabase;

    private FakeSigningService $signer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->signer = FakeSigningService::install();
    }

    public function test_admin_enables_signing_without_seeing_the_secret(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->withTwoFactor()->create(['position' => 'Wakasek']);

        $response = $this->actingAs($admin)->post(route('admin.users.signing.enable', $user));

        $response->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame('sk_01JDB8Q3V6X2N4M7P9R0S1T2V3', $user->signer_key_id);
        $this->assertNull($user->signer_totp_confirmed_at);
        $this->assertFalse($user->canSign());
        $this->assertSame($user->uuid, $this->signer->lastMeta('/v1/signers')['signer_ref']);
        $this->assertStringNotContainsString('ADMINMUSTNOTSEE', (string) json_encode(session()->all()));
        $this->assertTrue(AuditLog::query()->where('action', 'signer.enabled')->exists());
    }

    public function test_non_admins_cannot_manage_signers(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $other = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)->post(route('admin.users.signing.enable', $other))->assertForbidden();
        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_signer_activates_their_own_authenticator(): void
    {
        $user = User::factory()->signer()->create(['signer_totp_confirmed_at' => null]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('signature.activation.start'))
            ->assertSessionHas('signer_activation.manual_key', 'JBSWY3DPEHPK3PXP');

        $this->actingAs($user)->post(route('signature.activation.confirm'), ['totp_code' => '123456'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($user->refresh()->canSign());
        $this->assertSame(['totp_code' => '123456'], $this->signer->lastMeta('/v1/signers/'.$user->signer_key_id.'/totp/confirm'));
    }

    public function test_activation_requires_recent_password_confirmation(): void
    {
        $user = User::factory()->signer()->create(['signer_totp_confirmed_at' => null]);

        $this->actingAs($user)->post(route('signature.activation.start'))
            ->assertRedirect(route('password.confirm'));
        $this->assertSame([], $this->signer->paths());
    }

    public function test_activation_page_shows_status(): void
    {
        $user = User::factory()->signer()->create();

        $this->actingAs($user)->get(route('signature.edit'))->assertInertia(fn (Assert $page) => $page
            ->component('settings/Signature')
            ->where('signature.enabled', true)
            ->where('signature.activated', true)
            ->where('activation', null));
    }

    public function test_wrong_activation_code_is_shown_on_the_field(): void
    {
        $user = User::factory()->signer()->create(['signer_totp_confirmed_at' => null]);
        $this->signer->failNext(422, 'totp_invalid');

        $this->actingAs($user)->post(route('signature.activation.confirm'), ['totp_code' => '000000'])
            ->assertSessionHasErrors('totp_code');
        $this->assertFalse($user->refresh()->canSign());
    }

    public function test_admin_resets_and_revokes(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->signer()->create();

        $this->actingAs($admin)->post(route('admin.users.signing.reset', $user), ['reason' => 'HP hilang'])
            ->assertSessionHasNoErrors();
        $this->assertNull($user->refresh()->signer_totp_confirmed_at);

        $this->actingAs($admin)->post(route('admin.users.signing.revoke', $user), ['reason' => 'key_compromise'])
            ->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertNotNull($user->signer_revoked_at);
        $this->assertFalse($user->hasSignerKey());
        $this->assertSame('key_compromise', $this->signer->lastMeta('/v1/signers/'.$user->signer_key_id.'/revoke')['reason']);
    }

    public function test_admin_creates_user_and_sends_password_link(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Rina', 'email' => 'rina@example.sch.id', 'role' => 'staff', 'nip' => '199001012015012001',
        ])->assertSessionHasNoErrors();

        $rina = User::query()->where('email', 'rina@example.sch.id')->sole();
        $this->assertNotNull($rina->uuid);
        Notification::assertSentTo($rina, ResetPassword::class);
    }
}
