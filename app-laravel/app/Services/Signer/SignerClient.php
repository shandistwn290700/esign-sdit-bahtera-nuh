<?php

declare(strict_types=1);

namespace App\Services\Signer;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * HTTP client for the internal Signing Service (signing-service/openapi.yaml, /v1).
 *
 * Rules from docs/signing-contract.md: always send input_sha256 and X-Request-Id, never log or
 * persist TOTP codes or otpauth URIs, never retry signDocument automatically.
 */
final class SignerClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout,
        private readonly int $connectTimeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: (string) config('signer.url'),
            token: (string) config('signer.token'),
            timeout: (int) config('signer.timeout'),
            connectTimeout: (int) config('signer.connect_timeout'),
        );
    }

    /** @return array<string, mixed> */
    public function createSigner(User $user, ?User $actor, string $requestId): array
    {
        return $this->json('post', '/v1/signers', $requestId, array_filter([
            'signer_ref' => $user->uuid,
            'full_name' => $user->name,
            'position' => $user->position ?: 'Pegawai',
            'email' => $user->email,
            'nip' => $user->nip ?: null,
            'actor_ref' => $actor?->uuid,
        ], fn (mixed $v): bool => $v !== null));
    }

    /** @return array<string, mixed> */
    public function getSigner(string $keyId, string $requestId): array
    {
        return $this->json('get', "/v1/signers/{$keyId}", $requestId);
    }

    /** @return array<string, mixed> */
    public function confirmTotp(string $keyId, string $code, string $requestId): array
    {
        return $this->json('post', "/v1/signers/{$keyId}/totp/confirm", $requestId, ['totp_code' => $code]);
    }

    /** Returns the new otpauth:// URI. Show it once to the signer; never store it. */
    public function resetTotp(string $keyId, User $actor, string $reason, string $requestId): string
    {
        $body = $this->json('post', "/v1/signers/{$keyId}/totp/reset", $requestId, [
            'actor_ref' => $actor->uuid,
            'reason' => $reason,
        ]);

        return (string) $body['otpauth_uri'];
    }

    /** @return array<string, mixed> */
    public function revokeSigner(string $keyId, string $reason, User $actor, string $requestId, ?string $note = null): array
    {
        return $this->json('post', "/v1/signers/{$keyId}/revoke", $requestId, array_filter([
            'reason' => $reason,
            'note' => $note,
            'actor_ref' => $actor->uuid,
        ], fn (mixed $v): bool => $v !== null));
    }

    /**
     * @param  list<array{name: string, order: int, page: int, x: float, y: float, width: float, height: float}>  $fields
     */
    public function prepareDocument(string $pdf, array $fields, string $requestId): PdfResult
    {
        $response = $this->multipart('/v1/documents/prepare', $requestId, $pdf, [
            'input_sha256' => hash('sha256', $pdf),
            'fields' => $fields,
        ]);

        return PdfResult::fromResponse($response);
    }

    /**
     * @param  array{name: string, position: string, nip?: string|null}  $appearance
     */
    public function signDocument(
        string $pdf,
        string $fieldName,
        string $keyId,
        string $totpCode,
        string $verifyUrl,
        bool $certify,
        array $appearance,
        ?string $reason,
        ?string $location,
        ?User $actor,
        string $requestId,
    ): SignResult {
        $response = $this->multipart('/v1/documents/sign', $requestId, $pdf, array_filter([
            'input_sha256' => hash('sha256', $pdf),
            'field_name' => $fieldName,
            'key_id' => $keyId,
            'totp_code' => $totpCode,
            'verify_url' => $verifyUrl,
            'certify' => $certify,
            'appearance' => array_filter($appearance, fn (mixed $v): bool => $v !== null && $v !== ''),
            'reason' => $reason,
            'location' => $location,
            'actor_ref' => $actor?->uuid,
        ], fn (mixed $v): bool => $v !== null));

        return SignResult::fromResponse($response);
    }

    /** @return array<string, mixed> */
    public function validateDocument(string $pdf, string $requestId): array
    {
        return $this->decode($this->multipart('/v1/documents/validate', $requestId, $pdf, null));
    }

    public function caChainPem(string $requestId): string
    {
        return $this->send(fn (PendingRequest $r): Response => $r->get('/v1/pki/chain'), $requestId)->body();
    }

    public function crlDer(string $requestId): string
    {
        return $this->send(fn (PendingRequest $r): Response => $r->get('/v1/pki/crl'), $requestId)->body();
    }

    /** @return array{cms_signature: string, certificate_serial: string, signed_at: string} */
    public function signAuditAnchor(string $chainHead, int $lastEntryId, string $date, string $requestId): array
    {
        /** @var array{cms_signature: string, certificate_serial: string, signed_at: string} */
        return $this->json('post', '/v1/attestations/audit-anchor', $requestId, [
            'chain_head_sha256' => $chainHead,
            'last_entry_id' => $lastEntryId,
            'anchored_date' => $date,
        ]);
    }

    public static function newRequestId(): string
    {
        return (string) Str::uuid();
    }

    // ------------------------------------------------------------------------------------

    private function request(string $requestId): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->withHeaders(['X-Request-Id' => $requestId])
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, string $requestId, ?array $body = null): array
    {
        $response = $this->send(
            fn (PendingRequest $r): Response => $method === 'get' ? $r->get($path) : $r->post($path, $body ?? []),
            $requestId,
        );

        return $this->decode($response);
    }

    /** @param  array<string, mixed>|null  $meta */
    private function multipart(string $path, string $requestId, string $pdf, ?array $meta): Response
    {
        return $this->send(function (PendingRequest $r) use ($path, $pdf, $meta): Response {
            $r = $r->attach('file', $pdf, 'document.pdf', ['Content-Type' => 'application/pdf']);

            return $r->post($path, $meta === null ? [] : ['meta' => json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        }, $requestId);
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(callable $call, string $requestId): Response
    {
        try {
            $response = $call($this->request($requestId));
        } catch (ConnectionException $e) {
            throw new SignerException('signer_unreachable', 503, $e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $problem = $response->json();
            $retryAfter = $response->header('Retry-After');
            throw new SignerException(
                errorCode: is_array($problem) && isset($problem['code']) ? (string) $problem['code'] : 'signer_error',
                status: $response->status(),
                detail: is_array($problem) ? ($problem['detail'] ?? $problem['title'] ?? null) : null,
                retryAfter: $retryAfter !== '' ? (int) $retryAfter : null,
            );
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $data = $response->json();
        if (! is_array($data)) {
            throw new SignerException('signer_bad_response', 502, 'Expected a JSON object');
        }

        return $data;
    }
}
