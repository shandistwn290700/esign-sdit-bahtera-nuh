<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Signer\SignerClient;
use App\Services\Signer\SignerException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SignerClientTest extends TestCase
{
    private function client(): SignerClient
    {
        return new SignerClient('http://signer.test', 'tok', 5, 1);
    }

    public function test_problem_json_becomes_signer_exception(): void
    {
        Http::fake(['*' => Http::response(['code' => 'totp_locked', 'status' => 429, 'title' => 'x'], 429, ['Retry-After' => '120'])]);

        try {
            $this->client()->confirmTotp('sk_x', '123456', 'rid');
            $this->fail('expected exception');
        } catch (SignerException $e) {
            $this->assertSame('totp_locked', $e->errorCode);
            $this->assertSame(120, $e->retryAfter);
            $this->assertTrue($e->isUserFixable());
            $this->assertSame('Terlalu banyak percobaan. Coba lagi dalam 2 menit.', $e->userMessage());
        }
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer tok') && $r->hasHeader('X-Request-Id', 'rid'));
    }

    public function test_connection_failure_is_signer_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->expectExceptionObject(new SignerException('signer_unreachable', 503));
        try {
            $this->client()->crlDer('rid');
        } catch (SignerException $e) {
            $this->assertFalse($e->isUserFixable());
            throw $e;
        }
    }

    public function test_pdf_with_wrong_output_hash_is_rejected(): void
    {
        Http::fake(['*' => Http::response('%PDF-tampered', 200, [
            'X-Input-SHA256' => hash('sha256', '%PDF-in'),
            'X-Output-SHA256' => hash('sha256', '%PDF-something-else'),
        ])]);

        $this->expectException(SignerException::class);
        $this->client()->prepareDocument('%PDF-in', [], 'rid');
    }
}
