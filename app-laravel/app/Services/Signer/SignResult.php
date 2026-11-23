<?php

declare(strict_types=1);

namespace App\Services\Signer;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

final readonly class SignResult extends PdfResult
{
    public function __construct(
        string $pdf,
        string $inputSha256,
        string $outputSha256,
        public CarbonImmutable $signedAt,
        public string $certificateSerial,
    ) {
        parent::__construct($pdf, $inputSha256, $outputSha256);
    }

    protected static function build(Response $response, string $pdf, string $outputSha): static
    {
        $signedAt = $response->header('X-Signed-At');

        return new self(
            pdf: $pdf,
            inputSha256: strtolower($response->header('X-Input-SHA256')),
            outputSha256: $outputSha,
            // Normalise to the app timezone: Eloquent writes Carbon values to timezone-less
            // columns as-is, so a UTC instant would otherwise be stored 7 hours off in WIB.
            signedAt: ($signedAt !== '' ? CarbonImmutable::parse($signedAt) : CarbonImmutable::now())
                ->setTimezone((string) config('app.timezone')),
            certificateSerial: $response->header('X-Certificate-Serial'),
        );
    }
}
