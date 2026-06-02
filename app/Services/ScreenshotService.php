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
        $handle = fopen($file->getPathname(), 'rb');
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
        set_error_handler(fn () => null);

        if ($mimeType === 'image/png') {
            $image = imagecreatefrompng($file->getPathname());
        } else {
            $image = imagecreatefromjpeg($file->getPathname());
        }

        restore_error_handler();

        if (!($image instanceof \GdImage)) {
            throw new RuntimeException('The image could not be decoded. The file may be corrupt.');
        }

        ob_start();

        if ($mimeType === 'image/png') {
            imagepng($image);
        } else {
            imagejpeg($image, null, 90);
        }

        $stripped = ob_get_clean();
        imagedestroy($image);

        return $stripped;
    }

    public function store(UploadedFile $file, int $userId): array
    {
        $mimeType = $this->detectMimeType($file);
        $stripped = $this->reencodeImage($file, $mimeType);

        $extension = $mimeType === 'image/png' ? 'png' : 'jpg';
        $uuid      = (string) Str::uuid();
        $filename  = "{$uuid}.{$extension}";

        $year  = now()->format('Y');
        $month = now()->format('m');
        $path  = "screenshots/{$userId}/{$year}/{$month}/{$filename}";

        Storage::disk('screenshots')->put($path, $stripped);

        return [
            'id'            => $uuid,
            'filename'      => $filename,
            'original_name' => $file->getClientOriginalName(),
            'path'          => $path,
            'size_bytes'    => strlen($stripped),
            'mime_type'     => $mimeType,
        ];
    }
}
