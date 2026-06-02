<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class ValidImageMagicBytes implements ValidationRule
{
    private const PNG_MAGIC  = "\x89PNG\r\n\x1a\n";
    private const JPEG_MAGIC = "\xFF\xD8\xFF";

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!($value instanceof UploadedFile) || !is_readable($value->getPathname())) {
            $fail('The :attribute must be an uploaded image file.');
            return;
        }

        $handle = fopen($value->getPathname(), 'rb');
        $bytes  = fread($handle, 8);
        fclose($handle);

        $isPng  = str_starts_with($bytes, self::PNG_MAGIC);
        $isJpeg = str_starts_with($bytes, self::JPEG_MAGIC);

        if (!$isPng && !$isJpeg) {
            $fail('The :attribute must be a valid PNG or JPEG image.');
        }
    }
}
