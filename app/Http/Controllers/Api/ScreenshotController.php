<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScreenshotRequest;
use App\Models\Screenshot;
use App\Services\ScreenshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScreenshotController extends Controller
{
    public function __construct(private readonly ScreenshotService $service) {}

    public function store(StoreScreenshotRequest $request): JsonResponse
    {
        $file = $request->file('screenshot');

        try {
            $result = $this->service->store($file, $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $screenshot = Screenshot::create([
            'user_id'       => $request->user()->id,
            'filename'      => $result['filename'],
            'original_name' => $result['original_name'],
            'path'          => $result['path'],
            'size_bytes'    => $result['size_bytes'],
            'mime_type'     => $result['mime_type'],
        ]);

        return response()->json(
            $screenshot->only(['id', 'filename', 'original_name', 'path', 'size_bytes', 'mime_type']),
            201
        );
    }

    public function show(Screenshot $screenshot): JsonResponse
    {
        abort_if($screenshot->user_id !== auth()->id(), 403);

        return response()->json([
            'id'           => $screenshot->id,
            'download_url' => $this->signedDownloadUrl($screenshot),
        ]);
    }

    public function download(Request $request, Screenshot $screenshot): StreamedResponse
    {
        abort_unless(Storage::disk('screenshots')->exists($screenshot->path), 404);

        return Storage::disk('screenshots')->download(
            $screenshot->path,
            $screenshot->original_name,
            ['Content-Type' => $screenshot->mime_type]
        );
    }

    private function signedDownloadUrl(Screenshot $screenshot): string
    {
        return URL::temporarySignedRoute(
            'screenshots.download',
            now()->addMinutes(60),
            ['screenshot' => $screenshot->id]
        );
    }
}
