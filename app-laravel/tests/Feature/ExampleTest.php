<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_sends_guests_to_the_login_page()
    {
        $this->get(route('home'))->assertRedirect('/documents');
        $this->get('/documents')->assertRedirect(route('login'));
    }

    public function test_auth_pages_are_served_in_indonesian()
    {
        $this->assertSame('id', app()->getLocale());

        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'Email atau kata sandi salah.']);

        $this->post(route('login.store'), [])
            ->assertSessionHasErrors(['email' => 'Kolom email wajib diisi.']);
    }
}
