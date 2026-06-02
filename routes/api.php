<?php

use App\Http\Controllers\Api\ScreenshotController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/screenshots', [ScreenshotController::class, 'store']);
    Route::get('/screenshots/{screenshot}', [ScreenshotController::class, 'show']);
});

Route::get('/screenshots/{screenshot}/download', [ScreenshotController::class, 'download'])
    ->middleware('signed')
    ->name('screenshots.download');
