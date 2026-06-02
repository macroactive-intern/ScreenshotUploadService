<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    Storage::fake('screenshots');
});

// ── Upload: valid files ──────────────────────────────────────────────────────

test('accepts a valid PNG upload', function (): void {
    $file = UploadedFile::fake()->image('screen.png', 300, 200);

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(201)
        ->assertJsonStructure(['id', 'download_url']);
});

test('accepts a valid JPEG upload', function (): void {
    $file = UploadedFile::fake()->image('screen.jpg', 300, 200);

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(201)
        ->assertJsonStructure(['id', 'download_url']);
});

// ── Upload: magic-byte rejection ─────────────────────────────────────────────

test('rejects a PHP file with a .jpg extension', function (): void {
    $file = UploadedFile::fake()->createWithContent('exploit.jpg', '<?php system($_GET["cmd"]); ?>');

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['screenshot' => 'must be a valid PNG or JPEG image']);
});

test('rejects a plain text file disguised as PNG', function (): void {
    $file = UploadedFile::fake()->createWithContent('fake.png', 'not an image at all');

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(422);
});

test('rejects a file with no content', function (): void {
    $file = UploadedFile::fake()->create('empty.png', 0);

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(422);
});

// ── Upload: size limit ────────────────────────────────────────────────────────

test('rejects a file larger than 10 MB', function (): void {
    $file = UploadedFile::fake()->create('large.jpg', 11_000);

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(422);
});

// ── Upload: missing field ─────────────────────────────────────────────────────

test('returns 422 when no file is provided', function (): void {
    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', [])
        ->assertStatus(422);
});

// ── Auth guard ────────────────────────────────────────────────────────────────

test('unauthenticated upload returns 401', function (): void {
    $file = UploadedFile::fake()->image('screen.png');

    $this->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(401);
});

test('unauthenticated show returns 401', function (): void {
    $this->getJson('/api/screenshots/' . Str::uuid())
        ->assertStatus(401);
});

// ── Storage: sharded UUID path ────────────────────────────────────────────────

test('stores the file under a user/year/month path', function (): void {
    $file = UploadedFile::fake()->image('screen.jpg', 100, 100);

    $response = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file]);
    $response->assertStatus(201);

    $expectedPrefix = implode('/', [
        'screenshots',
        $this->user->id,
        now()->format('Y'),
        now()->format('m'),
    ]) . '/';

    $stored = Storage::disk('screenshots')->allFiles();
    expect($stored)->toHaveCount(1);
    expect($stored[0])->toStartWith($expectedPrefix);
});

test('PNG is stored with a .png extension', function (): void {
    $file = UploadedFile::fake()->image('screen.png');

    $response = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file]);
    $id = $response->json('id');

    $stored = Storage::disk('screenshots')->allFiles();
    expect($stored[0])->toEndWith("{$id}.png");
});

test('JPEG is stored with a .jpg extension', function (): void {
    $file = UploadedFile::fake()->image('screen.jpg');

    $response = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file]);
    $id = $response->json('id');

    $stored = Storage::disk('screenshots')->allFiles();
    expect($stored[0])->toEndWith("{$id}.jpg");
});

// ── Signed download URL ───────────────────────────────────────────────────────

test('store returns a non-empty signed download URL', function (): void {
    $file = UploadedFile::fake()->image('screen.png');

    $url = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file])
        ->assertStatus(201)
        ->json('download_url');

    expect($url)->toBeString()->not->toBeEmpty();
    expect($url)->toContain('/download');
    expect($url)->toContain('signature=');
    expect($url)->toContain('expires=');
});

test('download route returns the file for a valid signed URL', function (): void {
    $file = UploadedFile::fake()->image('screen.jpg');

    $storeResp = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file]);

    $downloadUrl = $storeResp->json('download_url');

    // Extract path + query from the full URL so $this->get() can use it.
    $parsed  = parse_url($downloadUrl);
    $testUrl = $parsed['path'] . '?' . $parsed['query'];

    $this->get($testUrl)->assertStatus(200);
});

test('download route rejects a tampered signature', function (): void {
    $file = UploadedFile::fake()->image('screen.png');

    $storeResp = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file]);

    $downloadUrl = $storeResp->json('download_url');
    $tampered    = preg_replace('/signature=[^&]+/', 'signature=fakesig', $downloadUrl);

    $parsed  = parse_url($tampered);
    $testUrl = $parsed['path'] . '?' . $parsed['query'];

    $this->get($testUrl)->assertStatus(403);
});

// ── Show endpoint ─────────────────────────────────────────────────────────────

test('GET /api/screenshots/{id} returns a fresh signed download URL', function (): void {
    $file       = UploadedFile::fake()->image('screen.jpg');
    $uploadResp = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file]);
    $id = $uploadResp->json('id');

    $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/screenshots/{$id}")
        ->assertStatus(200)
        ->assertJsonStructure(['id', 'download_url'])
        ->assertJsonPath('id', $id);
});

test('GET /api/screenshots/{id} returns 404 for an unknown UUID', function (): void {
    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/screenshots/' . Str::uuid())
        ->assertStatus(404);
});

// ── Ownership ─────────────────────────────────────────────────────────────────

test('user cannot access another users screenshot', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $file = UploadedFile::fake()->image('screen.png');
    $resp = $this->actingAs($owner, 'sanctum')
        ->postJson('/api/screenshots', ['screenshot' => $file]);
    $id = $resp->json('id');

    $this->actingAs($other, 'sanctum')
        ->getJson("/api/screenshots/{$id}")
        ->assertStatus(403);
});

// ── Magic-byte correctness (unit-style via service) ───────────────────────────

test('a PNG file renamed to .jpg is still identified as PNG from magic bytes', function (): void {
    $png  = UploadedFile::fake()->image('real.png', 50, 50);
    $path = $png->getRealPath();

    $wrapped = new UploadedFile($path, 'disguised.jpg', 'image/jpeg', null, true);

    $service  = app(\App\Services\ScreenshotService::class);
    $mimeType = $service->detectMimeType($wrapped);

    expect($mimeType)->toBe('image/png');
});
