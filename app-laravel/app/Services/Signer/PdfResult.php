<?php

declare(strict_types=1);

namespace App\Services\Signer;

use Illuminate\Http\Client\Response;

/** A PDF returned by the Signing Service, with its integrity re-checked on our side. */
readonly class PdfResult
{
    public function __construct(
        public string $pdf,
        public string $inputSha256,
        public string $outputSha256,
    ) {}

    public static function fromResponse(Response $response): static
    {
        $pdf = $response->body();
        $outputSha = hash('sha256', $pdf);

        if (! hash_equals(strtolower($response->header('X-Output-SHA256')), $outputSha)) {
            throw new SignerException('output_hash_mismatch', 502, 'Returned PDF does not match X-Output-SHA256');
        }

        return static::build($response, $pdf, $outputSha);
    }

    protected static function build(Response $response, string $pdf, string $outputSha): static
    {
        return new static($pdf, strtolower($response->header('X-Input-SHA256')), $outputSha);
    }
}
