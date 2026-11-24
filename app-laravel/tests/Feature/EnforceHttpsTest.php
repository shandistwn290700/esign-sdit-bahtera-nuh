<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Production behind Cloudflare: http visitors are redirected, https visitors get HSTS. */
final class EnforceHttpsTest extends TestCase
{
    use RefreshDatabase;

    private function productionLike(): void
    {
        config(['app.url' => 'https://esign.example.sch.id', 'session.secure' => true]);
        $this->app->detectEnvironment(fn () => 'production');
    }

    public function test_http_visitor_is_redirected_to_the_app_url(): void
    {
        $this->productionLike();

        $this->withHeaders(['CF-Visitor' => '{"scheme":"http"}', 'Host' => 'evil.example'])
            ->get('/verifikasi?x=1')
            ->assertStatus(301)
            ->assertRedirect('https://esign.example.sch.id/verifikasi?x=1');

        $this->withHeaders(['CF-Visitor' => '{"scheme":"http"}'])
            ->post('/login', ['email' => 'a@b.c', 'password' => 'x'])
            ->assertStatus(308);
    }

    public function test_https_visitor_is_treated_as_secure_and_gets_hsts(): void
    {
        $this->productionLike();

        $this->withHeaders(['CF-Visitor' => '{"scheme":"https"}'])
            ->get('/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_requests_without_cf_visitor_or_outside_production_are_untouched(): void
    {
        $this->productionLike();
        $this->get('/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');

        config(['session.secure' => null]);
        $this->withHeaders(['CF-Visitor' => '{"scheme":"http"}'])->get('/login')->assertOk();
    }
}
