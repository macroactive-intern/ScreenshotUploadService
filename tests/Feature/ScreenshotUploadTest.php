<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    Storage::fake('screenshots');

    // InMemoryFilesystemAdapter does not support temporaryUrl natively.
    // Provide a deterministic callback so the controller path can be exercised.
    Storage::disk('screenshots')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $exp): string =>
            url("/screenshots/{$path}?expires={$exp->getTimestamp()}")
    );
});

// ── Upload: valid files ──────────────────────────────────────────────────────

test('accepts a valid PNG upload', function (): void {
    $file = UploadedFile::fake()->image('screen.png', 300, 200);

    $this->actingAs($this->user, 'sanctum')->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(201)
        ->assertJsonStructure(['id', 'url']);
});

test('accepts a valid JPEG upload', function (): void {
    $file = UploadedFile::fake()->image('screen.jpg', 300, 200);

    $this->actingAs($this->user, 'sanctum')->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(201)
        ->assertJsonStructure(['id', 'url']);
});

// ── Upload: magic-byte rejection ─────────────────────────────────────────────

test('rejects a PHP file with a .jpg extension', function (): void {
    $file = UploadedFile::fake()->createWithContent('exploit.jpg', '<?php system($_GET["cmd"]); ?>');

    $this->actingAs($this->user, 'sanctum')->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(422)
        ->assertJsonPath('error', 'File is not a valid PNG or JPEG image.');
});

test('rejects a plain text file disguised as PNG', function (): void {
    $file = UploadedFile::fake()->createWithContent('fake.png', 'not an image at all');

    $this->actingAs($this->user, 'sanctum')->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(422);
});

test('rejects a file with no content', function (): void {
    $file = UploadedFile::fake()->create('empty.png', 0);

    $this->actingAs($this->user, 'sanctum')->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(422);
});

// ── Upload: size limit ────────────────────────────────────────────────────────

test('rejects a file larger than 10 MB', function (): void {
    $file = UploadedFile::fake()->create('large.jpg', 11_000);

    $this->actingAs($this->user, 'sanctum')->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(422);
});

// ── Upload: missing field ─────────────────────────────────────────────────────

test('returns 422 when no file is provided', function (): void {
    $this->actingAs($this->user, 'sanctum')->postJson('/api/screenshots', [])
        ->assertStatus(422);
});

// ── Storage: sharded UUID path ────────────────────────────────────────────────

test('stores the file at a sharded UUID path', function (): void {
    $file = UploadedFile::fake()->image('screen.jpg', 100, 100);

    $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/screenshots', ['screenshot' => $file]);
    $response->assertStatus(201);

    $id  = $response->json('id');
    $hex = str_replace('-', '', $id);

    $expectedPrefix = 'screenshots/' . substr($hex, 0, 2) . '/' . substr($hex, 2, 2) . '/' . $id;

    $stored = Storage::disk('screenshots')->allFiles();
    expect($stored)->toHaveCount(1);
    expect($stored[0])->toStartWith($expectedPrefix);
});

test('PNG is stored with a .png extension', function (): void {
    $file = UploadedFile::fake()->image('screen.png');

    $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/screenshots', ['screenshot' => $file]);
    $id       = $response->json('id');

    $stored = Storage::disk('screenshots')->allFiles();
    expect($stored[0])->toEndWith("{$id}.png");
});

test('JPEG is stored with a .jpg extension', function (): void {
    $file = UploadedFile::fake()->image('screen.jpg');

    $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/screenshots', ['screenshot' => $file]);
    $id       = $response->json('id');

    $stored = Storage::disk('screenshots')->allFiles();
    expect($stored[0])->toEndWith("{$id}.jpg");
});

// ── Signed URL ────────────────────────────────────────────────────────────────

test('returns a non-empty temporary URL after upload', function (): void {
    $file = UploadedFile::fake()->image('screen.png');

    $url = $this->actingAs($this->user, 'sanctum')->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(201)
        ->json('url');

    expect($url)->toBeString()->not->toBeEmpty();
});

// ── Show endpoint ─────────────────────────────────────────────────────────────

test('GET /api/screenshots/{id} returns a fresh signed URL', function (): void {
    $file       = UploadedFile::fake()->image('screen.jpg');
    $uploadResp = $this->actingAs($this->user, 'sanctum')->postJson('/api/screenshots', ['screenshot' => $file]);
    $id         = $uploadResp->json('id');

    $this->actingAs($this->user, 'sanctum')->getJson("/api/screenshots/{$id}")
        ->assertStatus(200)
        ->assertJsonStructure(['id', 'url'])
        ->assertJsonPath('id', $id);
});

test('GET /api/screenshots/{id} returns 404 for an unknown UUID', function (): void {
    $this->actingAs($this->user, 'sanctum')->getJson('/api/screenshots/' . Str::uuid())
        ->assertStatus(404);
});

// ── Magic-byte correctness (unit-style via service) ───────────────────────────

test('a PNG file renamed to .jpg is still identified as PNG from magic bytes', function (): void {
    // Create a real PNG, wrap it as an uploaded file with a .jpg name.
    $png  = UploadedFile::fake()->image('real.png', 50, 50);
    $path = $png->getRealPath();

    $wrapped = new UploadedFile($path, 'disguised.jpg', 'image/jpeg', null, true);

    $service  = app(\App\Services\ScreenshotService::class);
    $mimeType = $service->detectMimeType($wrapped);

    expect($mimeType)->toBe('image/png');
});
