<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDocumentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.config('signer.max_upload_kb')],
            'title' => ['required', 'string', 'max:200'],
            'number' => ['nullable', 'string', 'max:100'],
            'subject' => ['nullable', 'string', 'max:255'],
            'show_metadata_publicly' => ['boolean'],
            'signers' => ['required', 'array', 'min:1', 'max:10'],
            'signers.*.user_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'signers.*.page' => ['required', 'integer', 'min:1'],
            'signers.*.x' => ['required', 'numeric', 'min:0'],
            'signers.*.y' => ['required', 'numeric', 'min:0'],
            'signers.*.width' => ['required', 'numeric', 'between:72,400'],
            'signers.*.height' => ['required', 'numeric', 'between:72,400'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['file' => 'berkas PDF', 'title' => 'judul', 'signers' => 'penanda tangan'];
    }
}
