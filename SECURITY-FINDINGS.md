# SECURITY FINDINGS

No existing security issues found during review.

## Reviewed

- `routes/web.php` — single welcome route, no file handling
- `routes/api.php` — does not exist yet
- `app/Http/Controllers/Controller.php` — empty base class
- `app/Http/Requests/` — no request classes exist yet
- `config/filesystems.php` — stock Laravel 11 configuration
- `bootstrap/app.php` — stock middleware and routing configuration

## Baseline Notes (recorded for implementation guidance)

The `public` disk has `visibility: public` — any file stored there is web-accessible
without authentication. Screenshots will be stored on a dedicated private `screenshots`
disk to prevent this.

The `local` disk has `serve: true`, which allows temporary signed URLs to be issued
for private files. This is the intended mechanism for serving screenshots.

---

## Code Review Q&A

### 1. Why can't you trust the Content-Type header or the file extension?

Both are user-controlled metadata. A browser sets the Content-Type header based on
the file extension or its own heuristics — an attacker can rename `exploit.php` to
`exploit.png` and send `Content-Type: image/png`. The server receives whatever the
client claims. Trusting either value means the attacker controls the validation
outcome. The only reliable signal is the actual file bytes: a PNG always starts with
`\x89PNG\r\n\x1a\n` and a JPEG with `\xFF\xD8\xFF`, regardless of what the client
says. This service reads those bytes directly via `fread` in `ValidImageMagicBytes`
and `ScreenshotService::detectMimeType`, ignoring the extension and Content-Type
entirely for security decisions.

### 2. What is EXIF data? Give two examples of what it might reveal about a user.

EXIF (Exchangeable Image File Format) is metadata embedded inside image files by the
capturing device or editing software. It travels with the file and is invisible to
the viewer but readable by anyone who processes the bytes.

Two examples of what it can reveal:
- **GPS coordinates** — smartphones embed precise latitude/longitude by default,
  disclosing where the photo was taken (home address, workplace, hospital visit).
- **Device serial number / camera model** — uniquely identifies the hardware used,
  which can be used to link images across different uploads or platforms and
  de-anonymise a user.

This service strips EXIF by re-encoding every upload through GD
(`imagecreatefrompng` / `imagecreatefromjpeg`), which produces a fresh image
containing only pixel data.

### 3. Why does the storage path use a UUID instead of the original filename?

The original filename is user input and must be treated as untrusted. It can:
- Contain dangerous characters or path separators (`../`, `/`, `\`).
- Collide with an existing file and silently overwrite it.
- Leak personal information (e.g. `IMG_20240601_home_address.jpg`).
- Carry an extension that contradicts the actual file type.

A UUID generated server-side (`Str::uuid()`) is cryptographically random, globally
unique, contains no user-supplied characters, and carries no meaning an attacker can
predict or exploit. The original filename is stored separately in the database as
`original_name` for display purposes only — it never enters the storage path.

### 4. What is path traversal? How does your storage path generation prevent it?

Path traversal is an attack where user-controlled input containing sequences like
`../` is used to escape the intended directory. For example, an original filename of
`../../../etc/passwd` could cause a naive implementation to write outside the
uploads directory and overwrite system files, or a path like
`../public/index.php` could overwrite the application entry point.

This implementation prevents traversal at construction time by never including any
user input in the path. The path is assembled entirely from server-controlled values:

```
screenshots / {user_id} / {Y} / {m} / {uuid}.{ext}
```

- `user_id` — integer from the authenticated session, not from the request body.
- `Y` / `m` — `now()->format(...)`, server clock.
- `uuid` — `Str::uuid()`, server-generated.
- `ext` — derived from the magic-byte-detected MIME type, not the uploaded extension.

No segment of the path is derived from user input, so there is nothing to traverse.
