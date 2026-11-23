<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertRedirect('/documents');
        $this->get(route('documents.index'))->assertOk();
    }

    public function test_users_without_two_factor_are_sent_to_security_settings()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
    }

    public function test_deactivated_users_are_logged_out()
    {
        $this->actingAs(User::factory()->withTwoFactor()->create(['is_active' => false]));

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
