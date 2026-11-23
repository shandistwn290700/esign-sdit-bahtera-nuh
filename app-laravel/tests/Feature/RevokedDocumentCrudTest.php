<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Documents\RevokeDocument;
use App\Actions\Documents\SubmitDocument;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentSigner;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeSigningService;
use Tests\TestCase;

/** Update (metadata) and permanent delete, allowed only once a document is revoked. */
final class RevokedDocumentCrudTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.7\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Notification::fake();
        FakeSigningService::install();
    }

    /** @return array{0: User, 1: User, 2: Document} creator, signer, document */
    private function submitted(bool $revoke = true): array
    {
        $creator = User::factory()->withTwoFactor()->create();
        $signer = User::factory()->signer()->create();
        $doc = app(SubmitDocument::class)->handle($creator, self::PDF, [
            'title' => 'SK Lama',
            'number' => '421/001/2026',
            'signers' => [['user_id' => $signer->id, 'page' => 1, 'x' => 50, 'y' => 60, 'width' => 160, 'height' => 120]],
        ]);
        if ($revoke) {
            $doc = app(RevokeDocument::class)->handle($doc, $creator, 'Salah nomor surat');
        }

        return [$creator, $signer, $doc->fresh()];
    }

    public function test_show_page_offers_update_and_delete_only_after_revocation(): void
    {
        [$creator, , $active] = $this->submitted(revoke: false);
        $this->actingAs($creator)->get(route('documents.show', $active))
            ->assertInertia(fn (Assert $page) => $page->where('can.update', false)->where('can.delete', false));

        [$creator2, , $revoked] = $this->submitted();
        $this->actingAs($creator2)->get(route('documents.show', $revoked))
            ->assertInertia(fn (Assert $page) => $page->where('can.update', true)->where('can.delete', true));
    }

    public function test_creator_updates_metadata_of_a_revoked_document(): void
    {
        [$creator, , $doc] = $this->submitted();
        $pdfBefore = $doc->latestVersion()->firstOrFail()->sha256;

        $this->actingAs($creator)->put(route('documents.update', $doc), [
            'title' => 'SK Lama (dicabut)',
            'number' => '421/001/2026-R',
            'subject' => 'Diganti SK baru',
            'show_metadata_publicly' => '0',
        ])->assertSessionHasNoErrors();

        $doc->refresh();
        $this->assertSame('SK Lama (dicabut)', $doc->title);
        $this->assertSame('421/001/2026-R', $doc->number);
        $this->assertFalse($doc->show_metadata_publicly);
        $this->assertSame($pdfBefore, $doc->latestVersion()->firstOrFail()->sha256, 'the signed PDF is never touched');
        $log = AuditLog::query()->where('action', 'document.updated')->sole();
        $this->assertSame('SK Lama', $log->context['before']['title']);
    }

    public function test_active_documents_cannot_be_updated_or_deleted(): void
    {
        [$creator, , $doc] = $this->submitted(revoke: false);

        $this->actingAs($creator)->put(route('documents.update', $doc), ['title' => 'Diubah'])->assertForbidden();
        $this->actingAs($creator)->delete(route('documents.destroy', $doc), ['confirmation' => 'HAPUS'])->assertForbidden();
        $this->assertSame('SK Lama', $doc->fresh()->title);
    }

    public function test_only_creator_or_admin_may_manage(): void
    {
        [, $signer, $doc] = $this->submitted();
        $outsider = User::factory()->withTwoFactor()->create();

        // The signer can see the document but does not own it.
        $this->actingAs($signer)->put(route('documents.update', $doc), ['title' => 'X'])->assertForbidden();
        $this->actingAs($outsider)->delete(route('documents.destroy', $doc), ['confirmation' => 'HAPUS'])->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('documents.update', $doc), ['title' => 'Oleh admin'])->assertSessionHasNoErrors();
        $this->assertSame('Oleh admin', $doc->fresh()->title);
    }

    public function test_delete_requires_typed_confirmation(): void
    {
        [$creator, , $doc] = $this->submitted();

        $this->actingAs($creator)->delete(route('documents.destroy', $doc), ['confirmation' => 'hapus'])
            ->assertSessionHasErrors('confirmation');

        $this->assertNotNull(Document::query()->find($doc->id));
    }

    public function test_delete_removes_rows_and_files_but_keeps_an_audit_snapshot(): void
    {
        [$creator, , $doc] = $this->submitted();
        $paths = DocumentVersion::query()->where('document_id', $doc->id)->pluck('path')->all();
        $this->assertNotEmpty($paths);

        $this->actingAs($creator)->delete(route('documents.destroy', $doc), ['confirmation' => 'HAPUS'])
            ->assertRedirect(route('documents.index'));

        $this->assertNull(Document::query()->find($doc->id));
        $this->assertSame(0, DocumentVersion::query()->where('document_id', $doc->id)->count());
        $this->assertSame(0, DocumentSigner::query()->where('document_id', $doc->id)->count());
        foreach ($paths as $path) {
            Storage::disk('documents')->assertMissing($path);
        }

        $log = AuditLog::query()->where('action', 'document.deleted')->sole();
        $this->assertSame($doc->public_id, $log->context['public_id']);
        $this->assertSame('SK Lama', $log->context['title']);
        $this->assertCount(count($paths), $log->context['versions']);
        // Earlier history of the document is still there and the chain still verifies.
        $this->assertTrue(AuditLog::query()->where('action', 'document.revoked')->where('subject_id', $doc->id)->exists());
        $this->artisan('audit:verify')->assertSuccessful();

        // The printed QR now resolves to "not found" (the chosen behaviour).
        $this->get(route('verify.show', $doc->public_id))->assertNotFound();
    }
}
