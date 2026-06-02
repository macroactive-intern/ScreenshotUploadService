<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ScreenshotService
{
    private const PNG_MAGIC  = "\x89PNG\r\n\x1a\n";
    private const JPEG_MAGIC = "\xFF\xD8\xFF";

    public function detectMimeType(UploadedFile $file): string
    {
        $handle = fopen($file->getRealPath(), 'rb');
        $header = fread($handle, 8);
        fclose($handle);

        if (str_starts_with($header, self::PNG_MAGIC)) {
            return 'image/png';
        }

        if (str_starts_with($header, self::JPEG_MAGIC)) {
            return 'image/jpeg';
        }

        throw new RuntimeException('File is not a valid PNG or JPEG image.');
    }

    private function reencodeImage(UploadedFile $file, string $mimeType): string
    {
        // Re-encoding via GD strips all EXIF/metadata since GD does not preserve it.
        set_error_handler(fn () => null);

        $image = match ($mimeType) {
            'image/png'  => imagecreatefrompng($file->getRealPath()),
            'image/jpeg' => imagecreatefromjpeg($file->getRealPath()),
        };

        restore_error_handler();

        if (!($image instanceof \GdImage)) {
            throw new RuntimeException('Failed to decode image data.');
        }

        ob_start();

        if ($mimeType === 'image/png') {
            imagepng($image);
        } else {
            imagejpeg($image, null, 90);
        }

        $data = ob_get_clean();
        imagedestroy($image);

        return $data;
    }

    public function store(UploadedFile $file, int $userId): array
    {
        $mimeType  = $this->detectMimeType($file);
        $imageData = $this->reencodeImage($file, $mimeType);

        $extension = $mimeType === 'image/png' ? 'png' : 'jpg';
        $uuid      = (string) Str::uuid();
        $filename  = "{$uuid}.{$extension}";

        $year  = now()->format('Y');
        $month = now()->format('m');
        $path  = "screenshots/{$userId}/{$year}/{$month}/{$filename}";

        Storage::disk('screenshots')->put($path, $imageData);

        return [
            'id'            => $uuid,
            'filename'      => $filename,
            'original_name' => $file->getClientOriginalName(),
            'path'          => $path,
            'size_bytes'    => strlen($imageData),
            'mime_type'     => $mimeType,
        ];
    }

}
