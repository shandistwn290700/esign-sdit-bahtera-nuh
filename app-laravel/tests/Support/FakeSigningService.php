<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * In-memory stand-in for the Signing Service, following signing-service/openapi.yaml closely
 * enough for Laravel-side tests (hash headers, problem+json errors, multipart meta).
 */
final class FakeSigningService
{
    /** @var list<array{path: string, meta: array<string, mixed>|null, headers: array<string, mixed>}> */
    public array $calls = [];

    /** @var array{0: int, 1: string, 2: int|null}|null */
    private ?array $nextError = null;

    public static function install(): self
    {
        $fake = new self;
        Http::preventStrayRequests();
        Http::fake(['signer.test/*' => fn (Request $request): PromiseInterface => $fake->handle($request)]);

        return $fake;
    }

    public function failNext(int $status, string $code, ?int $retryAfter = null): void
    {
        $this->nextError = [$status, $code, $retryAfter];
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_column($this->calls, 'path');
    }

    /** @return array<string, mixed>|null */
    public function lastMeta(string $path): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['path'] === $path) {
                return $call['meta'];
            }
        }

        return null;
    }

    private function handle(Request $request): PromiseInterface
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        [$pdf, $meta] = $this->parts($request);
        $this->calls[] = ['path' => $path, 'meta' => $meta, 'headers' => $request->headers()];

        if ($this->nextError !== null) {
            [$status, $code, $retry] = $this->nextError;
            $this->nextError = null;

            return Http::response(
                ['type' => 'about:blank', 'title' => 'error', 'status' => $status, 'code' => $code],
                $status,
                array_filter(['Content-Type' => 'application/problem+json', 'Retry-After' => $retry !== null ? (string) $retry : null]),
            );
        }

        return match (true) {
            $path === '/v1/documents/prepare' => $this->pdf($pdf, $pdf."\n%prepared\n"),
            $path === '/v1/documents/sign' => $this->pdf($pdf, $pdf."\n%signed {$meta['field_name']}\n", [
                'X-Signed-At' => '2026-11-21T03:00:00+00:00',
                'X-Certificate-Serial' => 'abc123',
            ]),
            $path === '/v1/documents/validate' => Http::response([
                'document_sha256' => hash('sha256', $pdf),
                'status' => str_contains($pdf, '%signed') ? 'valid' : 'unsigned',
                'modified_after_last_signature' => false,
                'signatures' => str_contains($pdf, '%signed') ? [[
                    'field_name' => 'sig_1', 'signer_name' => 'Budi', 'signed_at' => '2026-11-21T03:00:00Z',
                    'valid' => true, 'revoked' => false, 'problems' => [],
                ]] : [],
                'crl_this_update' => null,
            ]),
            $path === '/v1/signers' => Http::response($this->signer('sk_01JDB8Q3V6X2N4M7P9R0S1T2V3') + [
                'totp_enrollment' => ['otpauth_uri' => 'otpauth://totp/x?secret=ADMINMUSTNOTSEE&issuer=TTE', 'status' => 'pending'],
            ], 201),
            str_ends_with($path, '/totp/reset') => Http::response([
                'otpauth_uri' => 'otpauth://totp/TTE:Budi?secret=JBSWY3DPEHPK3PXP&issuer=TTE', 'status' => 'pending',
            ]),
            str_ends_with($path, '/totp/confirm'), str_ends_with($path, '/revoke') => Http::response($this->signer(basename(dirname($path)) ?: 'sk_x')),
            $path === '/v1/attestations/audit-anchor' => Http::response([
                'cms_signature' => base64_encode('cms'), 'certificate_serial' => 'a1', 'signed_at' => '2026-11-21T01:05:00Z',
            ]),
            $path === '/v1/pki/crl' => Http::response('crl-der', 200, ['Content-Type' => 'application/pkix-crl']),
            default => Http::response(['code' => 'validation_failed', 'status' => 404, 'title' => 'unknown'], 404),
        };
    }

    /** @param  array<string, string>  $extra */
    private function pdf(string $input, string $output, array $extra = []): PromiseInterface
    {
        return Http::response($output, 200, [
            'Content-Type' => 'application/pdf',
            'X-Input-SHA256' => hash('sha256', $input),
            'X-Output-SHA256' => hash('sha256', $output),
        ] + $extra);
    }

    /** @return array<string, mixed> */
    private function signer(string $keyId): array
    {
        return [
            'key_id' => $keyId,
            'signer_ref' => '00000000-0000-0000-0000-000000000000',
            'status' => 'active',
            'totp_status' => 'pending',
            'revoked_at' => null,
            'revocation_reason' => null,
            'certificate' => [
                'serial' => 'abc123', 'subject' => 'CN=x', 'not_before' => '2026-01-01T00:00:00Z',
                'not_after' => '2028-01-01T00:00:00Z', 'pem' => '', 'sha256_fingerprint' => str_repeat('0', 64),
            ],
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>|null} */
    private function parts(Request $request): array
    {
        if (! $request->isMultipart()) {
            return ['', $request->data() ?: null];
        }
        $pdf = '';
        $meta = null;
        foreach ($request->data() as $part) {
            if ($part['name'] === 'file') {
                $pdf = (string) $part['contents'];
            } elseif ($part['name'] === 'meta') {
                $meta = json_decode((string) $part['contents'], true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return [$pdf, $meta];
    }
}
