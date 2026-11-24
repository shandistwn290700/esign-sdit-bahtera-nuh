<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeSigningService;
use Tests\TestCase;

final class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private FakeSigningService $signer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->signer = FakeSigningService::install();
        $this->admin = User::factory()->admin()->create();
    }

    /** @return array<string, mixed> */
    private function payload(User $user, array $overrides = []): array
    {
        return $overrides + [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'position' => $user->position,
            'nip' => $user->nip,
            'phone' => $user->phone,
        ];
    }

    public function test_admin_edits_a_non_signer_and_the_change_is_audited(): void
    {
        $user = User::factory()->withTwoFactor()->create(['name' => 'Rina']);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $user), $this->payload($user, ['name' => 'Rina Wati', 'position' => 'Staf TU']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Rina Wati', $user->fresh()->name);
        $log = AuditLog::query()->where('action', 'user.updated')->sole();
        $this->assertSame(['name' => 'Rina Wati', 'position' => 'Staf TU'], $log->context['after']);
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame([], $this->signer->paths());
    }

    public function test_signer_identity_change_requires_certificate_reissue(): void
    {
        $signer = User::factory()->signer()->create(['name' => 'Budi']);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $signer), $this->payload($signer, ['name' => 'Budi Santoso']))
            ->assertSessionHasErrors('reissue_certificate');

        $this->assertSame('Budi', $signer->fresh()->name);
        $this->assertSame([], $this->signer->paths());
    }

    public function test_reissue_revokes_old_certificate_and_issues_a_new_one(): void
    {
        $signer = User::factory()->signer()->create(['name' => 'Budi', 'signer_key_id' => 'sk_old']);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $signer), $this->payload($signer, ['name' => 'Budi Santoso', 'reissue_certificate' => true]))
            ->assertSessionHasNoErrors();

        $signer->refresh();
        $this->assertSame('Budi Santoso', $signer->name);
        $this->assertSame(['/v1/signers/sk_old/revoke', '/v1/signers'], $this->signer->paths());
        $this->assertTrue($signer->hasSignerKey());
        $this->assertNotSame('sk_old', $signer->signer_key_id);
        $this->assertNull($signer->signer_totp_confirmed_at, 'the signer must re-activate the authenticator');
        $this->assertFalse($signer->canSign());
    }

    public function test_non_certificate_fields_of_a_signer_change_without_reissue(): void
    {
        $signer = User::factory()->signer()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $signer), $this->payload($signer, ['phone' => '081234567890']))
            ->assertSessionHasNoErrors();

        $this->assertSame('081234567890', $signer->fresh()->phone);
        $this->assertTrue($signer->fresh()->canSign());
        $this->assertSame([], $this->signer->paths());
    }

    public function test_admin_cannot_demote_or_deactivate_themselves(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->admin), $this->payload($this->admin, ['role' => 'staff']))
            ->assertSessionHasErrors('role');
        $this->actingAs($this->admin)
            ->post(route('admin.users.deactivate', $this->admin), ['reason' => 'uji coba'])
            ->assertSessionHasErrors('reason');

        $this->assertTrue($this->admin->fresh()->isAdmin());
        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_deactivation_ends_sessions_and_optionally_revokes_certificate(): void
    {
        $signer = User::factory()->signer()->create(['signer_key_id' => 'sk_budi']);
        DB::table('sessions')->insert([
            'id' => 'open-session', 'user_id' => $signer->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => time(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.users.deactivate', $signer), ['reason' => 'Pindah tugas', 'revoke_certificate' => true])
            ->assertSessionHasNoErrors();

        $signer->refresh();
        $this->assertFalse($signer->is_active);
        $this->assertFalse($signer->canSign());
        $this->assertNotNull($signer->signer_revoked_at);
        $this->assertSame(['/v1/signers/sk_budi/revoke'], $this->signer->paths());
        $this->assertDatabaseMissing('sessions', ['id' => 'open-session']);
        $this->assertSame('Pindah tugas', AuditLog::query()->where('action', 'user.deactivated')->sole()->context['reason']);

        // And the account can no longer log in.
        auth()->logout();
        $this->post(route('login.store'), ['email' => $signer->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivation_without_revocation_keeps_the_certificate(): void
    {
        $signer = User::factory()->signer()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.users.deactivate', $signer), ['reason' => 'Cuti panjang'])
            ->assertSessionHasNoErrors();

        $this->assertNull($signer->fresh()->signer_revoked_at);
        $this->assertSame([], $this->signer->paths());

        $this->actingAs($this->admin)->post(route('admin.users.activate', $signer))->assertSessionHasNoErrors();
        $this->assertTrue($signer->fresh()->canSign());
        $this->assertSame(1, AuditLog::query()->where('action', 'user.activated')->count());
    }

    public function test_enabling_signing_before_ca_explains_what_is_missing(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->signer->failNext(503, 'ca_not_initialized');

        $this->actingAs($this->admin)->post(route('admin.users.signing.enable', $user))
            ->assertSessionHasErrors(['action' => 'Sertifikat CA tanda tangan belum dipasang di server. Hubungi admin untuk menjalankan prosedur Root CA.']);

        $this->assertNull($user->fresh()->signer_key_id);
    }

    public function test_staff_cannot_manage_users(): void
    {
        $staff = User::factory()->withTwoFactor()->create();
        $other = User::factory()->withTwoFactor()->create();

        $this->actingAs($staff)->put(route('admin.users.update', $other), $this->payload($other, ['name' => 'X']))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.users.deactivate', $other), ['reason' => 'iseng saja'])->assertForbidden();
        $this->assertTrue($other->fresh()->is_active);
    }
}
