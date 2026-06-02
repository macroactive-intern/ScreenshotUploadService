<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    Storage::fake('screenshots');
});

// ── Re-encode sanity: size_bytes comes from stripped output, not the original ──

test('size_bytes reflects the re-encoded file not the original upload', function (): void {
    $file = new UploadedFile(
        base_path('tests/fixtures/with_exif.jpg'),
        'photo.jpg',
        'image/jpeg',
        null,
        true,
    );

    $response = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(201);

    $storedPath   = $response->json('path');
    $reportedSize = $response->json('size_bytes');

    $storedBytes  = Storage::disk('screenshots')->get($storedPath);

    // size_bytes must match what was actually written, not the fixture file size
    expect($reportedSize)->toBe(strlen($storedBytes));
    expect($reportedSize)->not->toBe(filesize(base_path('tests/fixtures/with_exif.jpg')));
});

// ── EXIF stripping: APP1 / Exif / camera metadata removed after GD re-encode ──

test('stored JPEG has no EXIF APP1 marker after upload', function (): void {
    $file = new UploadedFile(
        base_path('tests/fixtures/with_exif.jpg'),
        'photo.jpg',
        'image/jpeg',
        null,
        true,
    );

    $storedPath = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(201)
        ->json('path');

    $stored = Storage::disk('screenshots')->get($storedPath);

    expect($stored)->not->toContain("\xFF\xE1");   // APP1 marker gone
    expect($stored)->not->toContain('Exif');        // EXIF identifier gone
    expect($stored)->not->toContain('Canon');       // injected Make tag gone
});

// ── Original file is never stored directly ────────────────────────────────────

test('stored bytes differ from the original uploaded file', function (): void {
    $fixturePath = base_path('tests/fixtures/with_exif.jpg');
    $file = new UploadedFile($fixturePath, 'photo.jpg', 'image/jpeg', null, true);

    $storedPath = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(201)
        ->json('path');

    $original = file_get_contents($fixturePath);
    $stored   = Storage::disk('screenshots')->get($storedPath);

    expect($stored)->not->toBe($original);
});

// ── Re-encoded file is still a valid JPEG ─────────────────────────────────────

test('stored file is a decodable JPEG after EXIF stripping', function (): void {
    $file = new UploadedFile(
        base_path('tests/fixtures/with_exif.jpg'),
        'photo.jpg',
        'image/jpeg',
        null,
        true,
    );

    $storedPath = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(201)
        ->json('path');

    $stored = Storage::disk('screenshots')->get($storedPath);

    // Must still open as a valid JPEG in memory
    $img = imagecreatefromstring($stored);
    expect($img)->toBeInstanceOf(GdImage::class);
    imagedestroy($img);
});
