<?php

declare(strict_types=1);

return [
    // Internal Signing Service (ADR-0001). Never exposed publicly.
    'url' => env('SIGNER_URL', 'http://signer:8000'),
    'token' => env('SIGNER_TOKEN'),
    'timeout' => (int) env('SIGNER_TIMEOUT', 60),
    'connect_timeout' => (int) env('SIGNER_CONNECT_TIMEOUT', 5),

    // Base of the URL encoded in every QR stamp: {verify_base_url}/{public_id}.
    // Printed permanently on documents, so pick the final domain before go-live.
    'verify_base_url' => rtrim((string) env('VERIFY_BASE_URL', 'http://localhost:8000/v'), '/'),

    'sign_reason' => env('SIGNER_SIGN_REASON', 'Menyetujui dokumen'),
    'sign_location' => env('SIGNER_SIGN_LOCATION'),

    // Recipient of the daily signed audit-log anchor (ADR-0004). Must be outside this server.
    'audit_archive_email' => env('AUDIT_ARCHIVE_EMAIL'),

    // AES-256-GCM key for documents at rest, separate from APP_KEY so key rotation of one
    // does not touch the other. Back it up together with (but stored apart from) the files.
    'document_key' => env('DOCUMENT_ENCRYPTION_KEY'),

    'max_upload_kb' => 20 * 1024,
];
