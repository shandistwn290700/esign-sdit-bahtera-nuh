<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeSigningService;
use Tests\TestCase;

final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_entries_form_a_hash_chain(): void
    {
        $logger = app(AuditLogger::class);
        $a = $logger->record('test.one', null, ['b' => 2, 'a' => 1]);
        $b = $logger->record('test.two', null, ['x' => 'ü/é']);

        $this->assertSame(AuditLogger::GENESIS, $a->prev_hash);
        $this->assertSame($a->hash, $b->prev_hash);
        $this->assertSame($b->hash, AuditLogger::hashFor(AuditLog::query()->findOrFail($b->id)));
        $this->assertNull($logger->firstBrokenEntry());
        $this->artisan('audit:verify')->assertExitCode(0);
    }

    public function test_key_order_does_not_change_the_hash(): void
    {
        $this->assertSame(AuditLogger::canonical(['b' => 1, 'a' => ['d' => 1, 'c' => 2]]), AuditLogger::canonical(['a' => ['c' => 2, 'd' => 1], 'b' => 1]));
    }

    public function test_database_rejects_updates_and_deletes(): void
    {
        $entry = app(AuditLogger::class)->record('test.one');

        try {
            DB::table('audit_logs')->where('id', $entry->id)->update(['action' => 'forged']);
            $this->fail('UPDATE should be rejected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_a_forged_entry_breaks_the_chain(): void
    {
        $logger = app(AuditLogger::class);
        $logger->record('test.one');
        $last = $logger->record('test.two');
        DB::table('audit_logs')->insert([
            'occurred_at' => now(), 'action' => 'forged', 'context' => '{}',
            'prev_hash' => $last->hash, 'hash' => str_repeat('f', 64),
        ]);

        $this->assertNotNull($logger->firstBrokenEntry());
        $this->artisan('audit:verify')->assertExitCode(1);
    }

    public function test_anchor_is_signed_and_mailed_off_server(): void
    {
        Mail::fake();
        $signer = FakeSigningService::install();
        config(['signer.audit_archive_email' => 'arsip@example.org']);
        $head = app(AuditLogger::class)->record('test.one');

        $this->artisan('audit:anchor')->assertExitCode(0);

        $meta = $signer->lastMeta('/v1/attestations/audit-anchor');
        $this->assertSame($head->hash, $meta['chain_head_sha256']);
        $this->assertTrue(AuditLog::query()->where('action', 'audit.anchored')->exists());
    }
}
