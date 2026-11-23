<?php

declare(strict_types=1);

namespace Tests\Live;

use App\Actions\Documents\SignDocumentStep;
use App\Actions\Documents\SubmitDocument;
use App\Actions\Documents\VerifyUploadedDocument;
use App\Actions\Signers\ManageSigner;
use App\Enums\DocumentStatus;
use App\Models\User;
use App\Services\DocumentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Laravel -> real Signing Service (SoftHSM + TSA). Excluded from the default run.
 *
 *   docker compose -f compose.dev.yml run --rm -e SIGNER_URL=http://signer:8000 \
 *     -e SIGNER_TOKEN=... app vendor/bin/phpunit tests/Live
 */
#[Group('signer-live')]
final class SigningServiceLiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_against_the_real_signing_service(): void
    {
        if (str_contains((string) config('signer.url'), 'signer.test')) {
            $this->markTestSkipped('Set SIGNER_URL/SIGNER_TOKEN to a running Signing Service.');
        }
        Storage::fake('documents');
        Notification::fake();
        $totp = new Google2FA;

        $admin = User::factory()->admin()->create();
        $signer = User::factory()->withTwoFactor()->create(['name' => 'Budi Santoso', 'position' => 'Kepala Sekolah']);
        $creator = User::factory()->withTwoFactor()->create();

        $signers = app(ManageSigner::class);
        $signers->enable($signer, $admin);
        parse_str((string) parse_url($signers->startActivation($signer), PHP_URL_QUERY), $q);
        $secret = (string) $q['secret'];
        $signers->confirmActivation($signer, $totp->getCurrentOtp($secret));
        $this->assertTrue($signer->refresh()->canSign());

        $doc = app(SubmitDocument::class)->handle($creator, self::minimalPdf(), [
            'title' => 'Uji Integrasi',
            'signers' => [['user_id' => $signer->id, 'page' => 1, 'x' => 60, 'y' => 60, 'width' => 200, 'height' => 120]],
        ]);

        sleep(31 - time() % 30); // fresh TOTP step: the activation code was single-use
        app(SignDocumentStep::class)->handle($doc, $signer, $totp->getCurrentOtp($secret));

        $doc->refresh();
        $this->assertSame(DocumentStatus::Completed, $doc->status);

        $signedPdf = app(DocumentStorage::class)->read($doc->latestVersion()->firstOrFail());
        $result = app(VerifyUploadedDocument::class)->handle($signedPdf);
        $this->assertSame('valid', $result['signature_status'], json_encode($result['signatures']));
        $this->assertTrue($result['registered']);
        $this->assertSame('Budi Santoso', $result['signatures'][0]['signer_name']);

        $tampered = str_replace('/MediaBox [0 0 595 842]', '/MediaBox [0 0 595 843]', $signedPdf);
        $this->assertSame('invalid', app(VerifyUploadedDocument::class)->handle($tampered)['signature_status']);
    }

    private static function minimalPdf(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << >> >>',
        ];
        $out = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($out);
            $out .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }

        return $out.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
