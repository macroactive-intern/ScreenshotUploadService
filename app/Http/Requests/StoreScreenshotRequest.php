<?php

namespace App\Http\Requests;

use App\Rules\ValidImageMagicBytes;
use Illuminate\Foundation\Http\FormRequest;

class StoreScreenshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'screenshot' => [
                'required',
                'file',
                'max:10240',
                new ValidImageMagicBytes(),
            ],
        ];
    }
}
