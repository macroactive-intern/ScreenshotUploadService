<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    Storage::fake('screenshots');
});

// ── Test 1: accepts valid PNG ─────────────────────────────────────────────────

test('accepts valid PNG fixture and stores it with UUID-based path', function (): void {
    $file = new UploadedFile(
        base_path('tests/fixtures/valid.png'),
        'valid.png',
        'image/png',
        null,
        true,
    );

    $response = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('screenshots', ['user_id' => $this->user->id]);

    $storedPath = $response->json('path');
    $prefix     = implode('/', [
        'screenshots',
        $this->user->id,
        now()->format('Y'),
        now()->format('m'),
    ]);
    expect($storedPath)->toStartWith($prefix);

    $filename = $response->json('filename');
    expect($filename)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.png$/');
    expect($storedPath)->not->toContain('valid.png');
});

// ── Test 2: accepts valid JPEG ────────────────────────────────────────────────

test('accepts valid JPEG fixture and records correct mime type', function (): void {
    $file = new UploadedFile(
        base_path('tests/fixtures/valid.jpg'),
        'valid.jpg',
        'image/jpeg',
        null,
        true,
    );

    $response = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file]);

    $response->assertStatus(201);
    expect($response->json('mime_type'))->toBe('image/jpeg');
    expect($response->json('filename'))->toEndWith('.jpg');
});

// ── Test 3: rejects PHP file disguised as PNG ─────────────────────────────────

test('rejects PHP content disguised as PNG and stores nothing', function (): void {
    $file = new UploadedFile(
        base_path('tests/fixtures/php_disguised.png'),
        'exploit.png',
        'image/png',
        null,
        true,
    );

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(422);

    $this->assertDatabaseMissing('screenshots', ['user_id' => $this->user->id]);
    expect(Storage::disk('screenshots')->allFiles())->toHaveCount(0);
});

// ── Test 4: rejects empty file ────────────────────────────────────────────────

test('rejects empty file and stores nothing', function (): void {
    $file = new UploadedFile(
        base_path('tests/fixtures/empty.png'),
        'empty.png',
        'image/png',
        null,
        true,
    );

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(422);

    expect(Storage::disk('screenshots')->allFiles())->toHaveCount(0);
});

// ── Test 5: does not trust the original filename ──────────────────────────────

test('never uses the original filename in the storage path', function (): void {
    $file = new UploadedFile(
        base_path('tests/fixtures/valid.png'),
        '../../../evil.png',
        'image/png',
        null,
        true,
    );

    $response = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file]);

    $response->assertStatus(201);

    $storedPath = $response->json('path');
    expect($storedPath)->not->toContain('..');
    expect($storedPath)->not->toContain('evil.png');
    expect($response->json('filename'))->toMatch(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.png$/'
    );
});

// ── Test 6: signed URL expires after 60 minutes ───────────────────────────────

test('signed download URL is rejected after 60 minutes', function (): void {
    $file = new UploadedFile(
        base_path('tests/fixtures/valid.png'),
        'screen.png',
        'image/png',
        null,
        true,
    );

    $id = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->json('id');

    $url = $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/screenshots/{$id}")
        ->json('url');

    $parsed  = parse_url($url);
    $testUrl = $parsed['path'] . '?' . $parsed['query'];

    $this->travel(61)->minutes();
    $this->get($testUrl)->assertStatus(403);
});

// ── Test 7: ownership — another user cannot access the screenshot ─────────────

test('another user cannot retrieve the signed URL for someone elses screenshot', function (): void {
    $intruder = User::factory()->create();

    $file = new UploadedFile(
        base_path('tests/fixtures/valid.png'),
        'screen.png',
        'image/png',
        null,
        true,
    );

    $id = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->json('id');

    $this->actingAs($intruder, 'sanctum')
        ->getJson("/api/screenshots/{$id}")
        ->assertStatus(403);
});
