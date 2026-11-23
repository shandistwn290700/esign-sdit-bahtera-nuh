<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this
            ->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this
            ->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_is_stored_in_lowercase_so_the_user_can_still_log_in()
    {
        $user = User::factory()->withTwoFactor()->create();

        $this
            ->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'Test@Example.com',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('test@example.com', $user->refresh()->email);

        $this->post(route('logout'));

        // 2FA is mandatory, so valid credentials lead to the challenge for this exact user.
        $this->post(route('login.store'), [
            'email' => 'Test@Example.com',
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'))->assertSessionHas('login.id', $user->id);
    }

    public function test_email_must_be_unique_regardless_of_case()
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this
            ->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'Taken@Example.com',
            ]);

        $response
            ->assertSessionHasErrors('email')
            ->assertRedirect(route('profile.edit'));
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this
            ->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }
}
