<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Documents\SubmitDocument;
use App\Enums\DocumentStatus;
use App\Enums\SignerStepStatus;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentFinished;
use App\Notifications\DocumentSignedByYou;
use App\Notifications\SignatureRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeSigningService;
use Tests\TestCase;

final class DocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.7\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";

    private FakeSigningService $signer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Notification::fake();
        $this->signer = FakeSigningService::install();
    }

    /** @return array{0: User, 1: User, 2: User, 3: Document} */
    private function submitted(): array
    {
        $creator = User::factory()->withTwoFactor()->create();
        $first = User::factory()->signer()->create(['name' => 'Siti Aminah']);
        $second = User::factory()->signer()->create(['name' => 'Budi Santoso']);
        $doc = app(SubmitDocument::class)->handle($creator, self::PDF, [
            'title' => 'SK Pembagian Tugas',
            'number' => '421/001/2026',
            'signers' => [
                ['user_id' => $first->id, 'page' => 1, 'x' => 50, 'y' => 60, 'width' => 160, 'height' => 120],
                ['user_id' => $second->id, 'page' => 1, 'x' => 300, 'y' => 60, 'width' => 160, 'height' => 120],
            ],
        ]);

        return [$creator, $first, $second, $doc];
    }

    public function test_submit_prepares_fields_stores_versions_and_notifies_first_signer(): void
    {
        $creator = User::factory()->withTwoFactor()->create();
        $signer = User::factory()->signer()->create();

        $response = $this->actingAs($creator)->post(route('documents.store'), [
            'file' => UploadedFile::fake()->createWithContent('surat.pdf', self::PDF),
            'title' => 'Surat Keterangan',
            'signers' => [['user_id' => $signer->id, 'page' => 1, 'x' => 50, 'y' => 60, 'width' => 160, 'height' => 120]],
        ]);

        $doc = Document::query()->sole();
        $response->assertRedirect(route('documents.show', $doc));
        $this->assertSame(DocumentStatus::InProgress, $doc->status);
        $this->assertSame(1, $doc->current_step);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $doc->public_id);
        $this->assertCount(2, $doc->versions);
        $this->assertSame(hash('sha256', self::PDF), $doc->versions[0]->sha256);
        // Stored encrypted, never as plain PDF.
        $this->assertStringNotContainsString('%PDF', Storage::disk('documents')->get($doc->versions[0]->path));

        $meta = $this->signer->lastMeta('/v1/documents/prepare');
        $this->assertSame(hash('sha256', self::PDF), $meta['input_sha256']);
        $this->assertSame('sig_1', $meta['fields'][0]['name']);
        $this->assertSame('Bearer test-token-0000000000000000000000000000', $this->signer->calls[0]['headers']['Authorization'][0]);

        Notification::assertSentTo($signer, SignatureRequested::class);
        $this->assertTrue(AuditLog::query()->where('action', 'document.submitted')->exists());
    }

    public function test_only_activated_signers_can_be_assigned(): void
    {
        $creator = User::factory()->withTwoFactor()->create();
        $notActivated = User::factory()->signer()->create(['signer_totp_confirmed_at' => null]);

        $this->actingAs($creator)->post(route('documents.store'), [
            'file' => UploadedFile::fake()->createWithContent('surat.pdf', self::PDF),
            'title' => 'Surat',
            'signers' => [['user_id' => $notActivated->id, 'page' => 1, 'x' => 50, 'y' => 60, 'width' => 160, 'height' => 120]],
        ])->assertSessionHasErrors('signers.0.user_id');

        $this->assertSame(0, Document::query()->count());
        $this->assertSame([], $this->signer->paths());
    }

    public function test_placement_errors_from_the_signing_service_return_to_the_form(): void
    {
        $creator = User::factory()->withTwoFactor()->create();
        $signer = User::factory()->signer()->create();
        $this->signer->failNext(422, 'field_out_of_bounds');

        $this->actingAs($creator)->post(route('documents.store'), [
            'file' => UploadedFile::fake()->createWithContent('surat.pdf', self::PDF),
            'title' => 'Surat',
            'signers' => [['user_id' => $signer->id, 'page' => 1, 'x' => 500, 'y' => 60, 'width' => 160, 'height' => 120]],
        ])->assertSessionHasErrors(['signers' => 'Kotak QR berada di luar halaman.']);

        $this->assertSame(0, Document::query()->count());
    }

    public function test_sequential_signing_to_completion(): void
    {
        [$creator, $first, $second, $doc] = $this->submitted();

        // Second signer cannot jump the queue.
        $this->actingAs($second)->from(route('documents.show', $doc))
            ->post(route('documents.sign', $doc), ['totp_code' => '123456'])
            ->assertSessionHasErrors(['action' => 'Belum giliran Anda untuk menandatangani dokumen ini.']);

        $this->actingAs($first)->post(route('documents.sign', $doc), ['totp_code' => '123456'])
            ->assertSessionHasNoErrors();
        $meta = $this->signer->lastMeta('/v1/documents/sign');
        $this->assertSame('sig_1', $meta['field_name']);
        $this->assertTrue($meta['certify']);
        $this->assertSame($first->signer_key_id, $meta['key_id']);
        $this->assertSame('https://verifikasi.test/v/'.$doc->public_id, $meta['verify_url']);
        Notification::assertSentTo($first, DocumentSignedByYou::class);
        Notification::assertSentTo($second, SignatureRequested::class);

        $this->actingAs($second)->post(route('documents.sign', $doc), ['totp_code' => '654321'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($this->signer->lastMeta('/v1/documents/sign')['certify']);

        $doc->refresh()->load('signers', 'versions');
        $this->assertSame(DocumentStatus::Completed, $doc->status);
        $this->assertNull($doc->current_step);
        $this->assertCount(4, $doc->versions);
        $this->assertTrue($doc->signers->every(fn ($s): bool => $s->status === SignerStepStatus::Signed));
        $this->assertSame('abc123', $doc->signers[0]->certificate_serial);
        // The Signing Service reports 03:00 UTC; it must read back as the same instant.
        $this->assertSame('2026-11-21T03:00:00+00:00', $doc->signers[0]->signed_at->utc()->toIso8601String());
        Notification::assertSentTo($creator, DocumentFinished::class);

        $this->get(route('verify.show', $doc->public_id))->assertInertia(fn (Assert $page) => $page
            ->component('verify/Show')
            ->where('verification.status.label', 'VALID')
            ->where('verification.title', 'SK Pembagian Tugas')
            ->has('verification.signatures', 2)
            ->where('verification.signatures.0.name', 'Siti Aminah'));
    }

    public function test_wrong_totp_keeps_the_step_pending_and_is_audited(): void
    {
        [, $first, , $doc] = $this->submitted();
        $this->signer->failNext(422, 'totp_invalid');

        $this->actingAs($first)->post(route('documents.sign', $doc), ['totp_code' => '000000'])
            ->assertSessionHasErrors(['totp_code' => 'Kode salah. Periksa jam di HP Anda dan coba lagi.']);

        $this->assertSame(SignerStepStatus::Pending, $doc->signers()->first()->status);
        $this->assertCount(2, $doc->versions()->get());
        $failed = AuditLog::query()->where('action', 'document.sign_failed')->sole();
        $this->assertSame('totp_invalid', $failed->context['error_code']);
        $this->assertArrayNotHasKey('totp_code', $failed->context);
    }

    public function test_lockout_message_uses_retry_after(): void
    {
        [, $first, , $doc] = $this->submitted();
        $this->signer->failNext(429, 'totp_locked', 600);

        $this->actingAs($first)->post(route('documents.sign', $doc), ['totp_code' => '000000'])
            ->assertSessionHasErrors(['totp_code' => 'Terlalu banyak percobaan. Coba lagi dalam 10 menit.']);
    }

    public function test_signing_service_outage_is_reported_as_a_general_error(): void
    {
        [, $first, , $doc] = $this->submitted();
        $this->signer->failNext(503, 'hsm_unavailable');

        $this->actingAs($first)->post(route('documents.sign', $doc), ['totp_code' => '123456'])
            ->assertSessionHasErrors(['action' => 'Modul kunci tanda tangan tidak dapat diakses. Coba lagi beberapa saat; bila berulang, hubungi admin.']);
    }

    public function test_current_signer_can_reject(): void
    {
        [$creator, $first, , $doc] = $this->submitted();

        $this->actingAs($first)->post(route('documents.reject', $doc), ['reason' => 'Nomor surat salah'])
            ->assertSessionHasNoErrors();

        $doc->refresh()->load('signers');
        $this->assertSame(DocumentStatus::Rejected, $doc->status);
        $this->assertSame(SignerStepStatus::Rejected, $doc->signers[0]->status);
        $this->assertSame(SignerStepStatus::Skipped, $doc->signers[1]->status);
        Notification::assertSentTo($creator, DocumentFinished::class);
    }

    public function test_revocation_by_creator_shows_on_public_page(): void
    {
        [$creator, $first, , $doc] = $this->submitted();

        $this->actingAs($first)->post(route('documents.revoke', $doc), ['reason' => 'Salah kirim dokumen'])
            ->assertForbidden();

        $this->actingAs($creator)->post(route('documents.revoke', $doc), ['reason' => 'Salah kirim dokumen'])
            ->assertSessionHasNoErrors();

        $this->get(route('verify.show', $doc->public_id))->assertInertia(fn (Assert $page) => $page
            ->where('verification.status.label', 'DICABUT'));
    }

    public function test_pages_receive_unwrapped_document_props(): void
    {
        [$creator, $first, , $doc] = $this->submitted();

        $this->actingAs($first)->get(route('documents.show', $doc))->assertInertia(fn (Assert $page) => $page
            ->component('documents/Show')
            ->where('document.public_id', $doc->public_id)
            ->where('document.status.value', 'in_progress')
            ->has('document.signers', 2)
            ->where('document.signers.0.status', 'pending')
            ->where('can.sign', true)
            ->where('can.revoke', false));

        $this->actingAs($first)->get(route('documents.index'))->assertInertia(fn (Assert $page) => $page
            ->component('documents/Index')
            ->has('awaiting', 1)
            ->where('awaiting.0.public_id', $doc->public_id));

        $this->actingAs($creator)->get(route('documents.index'))->assertInertia(fn (Assert $page) => $page
            ->has('created', 1)
            ->where('created.0.signers.1.user.name', 'Budi Santoso'));
    }

    public function test_outsiders_cannot_view_or_download(): void
    {
        [, , , $doc] = $this->submitted();
        $outsider = User::factory()->withTwoFactor()->create();

        $this->actingAs($outsider)->get(route('documents.show', $doc))->assertForbidden();
        $this->actingAs($outsider)->get(route('documents.file', $doc))->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('documents.show', $doc))->assertOk();
    }

    public function test_download_returns_latest_version_and_is_audited(): void
    {
        [$creator, , , $doc] = $this->submitted();

        $response = $this->actingAs($creator)->get(route('documents.file', $doc));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringEndsWith("%prepared\n", $response->getContent());
        $this->assertTrue(AuditLog::query()->where('action', 'document.downloaded')->exists());
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_download_button_sends_an_attachment(): void
    {
        [$creator, , , $doc] = $this->submitted();

        $response = $this->actingAs($creator)->get(route('documents.file', ['document' => $doc, 'download' => 1]));

        $response->assertOk();
        $this->assertMatchesRegularExpression('/^attachment; filename="[a-z0-9-]+\.pdf"$/', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('download', AuditLog::query()->where('action', 'document.downloaded')->sole()->context['mode']);
    }

    public function test_hidden_metadata_is_not_public(): void
    {
        [, , , $doc] = $this->submitted();
        $doc->forceFill(['show_metadata_publicly' => false])->save();

        $this->get(route('verify.show', $doc->public_id))->assertInertia(fn (Assert $page) => $page
            ->where('verification.title', null)
            ->where('verification.number', null)
            ->where('verification.status.label', 'DALAM PROSES'));
    }

    public function test_unknown_public_id_is_404(): void
    {
        $this->get('/v/'.str_repeat('a', 32))->assertNotFound();
        $this->get('/v/not-a-valid-id')->assertNotFound();
    }

    public function test_upload_verification_matches_a_registered_signed_version(): void
    {
        [, $first, , $doc] = $this->submitted();
        $this->actingAs($first)->post(route('documents.sign', $doc), ['totp_code' => '123456']);
        auth()->logout();
        $signedPdf = self::PDF."\n%prepared\n\n%signed sig_1\n";

        $this->post(route('verify.check'), [
            'file' => UploadedFile::fake()->createWithContent('cek.pdf', $signedPdf),
        ])->assertInertia(fn (Assert $page) => $page
            ->component('verify/Upload')
            ->where('result.signature_status', 'valid')
            ->where('result.registered', true)
            ->where('result.is_latest_version', true)
            ->where('result.verification.public_id', $doc->public_id));
    }
}
