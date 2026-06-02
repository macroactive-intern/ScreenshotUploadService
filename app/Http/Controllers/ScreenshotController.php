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

        // id is not in $fillable (it is set by the system, not user input).
        $screenshot     = new Screenshot([
            'user_id'       => $request->user()->id,
            'filename'      => $result['filename'],
            'original_name' => $result['original_name'],
            'path'          => $result['path'],
            'size_bytes'    => $result['size_bytes'],
            'mime_type'     => $result['mime_type'],
        ]);
        $screenshot->id = $result['id'];
        $screenshot->save();

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
