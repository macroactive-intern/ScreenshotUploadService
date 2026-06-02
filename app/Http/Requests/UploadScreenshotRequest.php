<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadScreenshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Size limit only — MIME type is validated via magic bytes in ScreenshotService,
            // not here, because extension/Content-Type can be spoofed by the client.
            'screenshot' => ['required', 'file', 'max:10240'],
        ];
    }
}
