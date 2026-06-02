<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadScreenshotRequest;
use App\Models\Screenshot;
use App\Services\ScreenshotService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ScreenshotController extends Controller
{
    public function __construct(private readonly ScreenshotService $service) {}

    public function store(UploadScreenshotRequest $request): JsonResponse
    {
        try {
            $result = $this->service->store($request->file('screenshot'));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $screenshot = Screenshot::create([
            'id'        => $result['id'],
            'path'      => $result['path'],
            'mime_type' => $result['mime_type'],
        ]);

        return response()->json([
            'id'  => $screenshot->id,
            'url' => $this->service->temporaryUrl($screenshot->path),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $screenshot = Screenshot::findOrFail($id);

        return response()->json([
            'id'  => $screenshot->id,
            'url' => $this->service->temporaryUrl($screenshot->path),
        ]);
    }
}
