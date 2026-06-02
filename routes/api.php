<?php

use App\Http\Controllers\ScreenshotController;
use Illuminate\Support\Facades\Route;

Route::post('/screenshots', [ScreenshotController::class, 'store']);
Route::get('/screenshots/{id}', [ScreenshotController::class, 'show']);
