<?php

declare(strict_types=1);

namespace App\Domains\Documents\Services;

use App\Support\Exceptions\DomainException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Validates and stores uploaded files.
 *
 * The disk is named, never the driver — local in development, S3 or MinIO in
 * production — so nothing in the domain knows or cares where a file physically
 * lives. Paths are stored, never URLs: the disk is private, and moving from
 * local to object storage must not invalidate existing rows.
 */
final class DocumentStorage
{
    /**
     * Magic-byte signatures for the formats Naipay accepts.
     *
     * The browser-supplied content type is attacker-controlled and the file
     * extension is worse, so the type is determined from the bytes. A PHP
     * script renamed to `passport.pdf` must not pass.
     *
     * @var array<string, array<int, string>>
     */
    private const SIGNATURES = [
        'application/pdf' => ['%PDF-'],
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png' => ["\x89PNG\r\n\x1A\n"],
        'image/webp' => ['RIFF'],
        'image/heic' => ['ftypheic', 'ftypheix', 'ftypmif1'],
    ];

    /**
     * Stores an uploaded file and returns what the document record needs.
     *
     * @return array{path: string, name: string, mime_type: string, size: int, checksum: string}
     */
    public function store(UploadedFile $file, string $directory): array
    {
        $this->assertIsAcceptable($file);

        $mimeType = $this->detectMimeType($file);
        $checksum = hash_file('sha256', $file->getRealPath());

        /*
         * The stored filename is generated, never taken from the upload.
         * An attacker-supplied name can carry path traversal, a null byte, or
         * a second extension — and on a case-insensitive filesystem it can
         * collide with an existing file.
         */
        $storedName = Str::uuid()->toString().'.'.$this->extensionFor($mimeType);

        $path = $this->disk()->putFileAs($directory, $file, $storedName);

        if ($path === false) {
            throw new DomainException('The document could not be stored. Please try again.');
        }

        return [
            'path' => $path,
            // The original name is kept for display and download only; it is
            // never used to build a path.
            'name' => $this->sanitiseDisplayName($file->getClientOriginalName()),
            'mime_type' => $mimeType,
            'size' => $file->getSize(),
            'checksum' => $checksum !== false ? $checksum : '',
        ];
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function read(string $path): string
    {
        if (! $this->exists($path)) {
            throw new DomainException('The stored file for this document is missing.');
        }

        return (string) $this->disk()->get($path);
    }

    /**
     * Streams a file for download through the application, so access is
     * authorised and audited. There is no public URL to leak or share.
     */
    public function stream(string $path): StreamedResponse
    {
        if (! $this->exists($path)) {
            throw new DomainException('The stored file for this document is missing.');
        }

        return $this->disk()->download($path);
    }

    public function delete(string $path): void
    {
        if ($this->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    private function assertIsAcceptable(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new DomainException(
                'The upload did not complete. Please try again.',
                ['file' => ['The file could not be read.']],
            );
        }

        $maxKilobytes = (int) config('naipay.documents.max_upload_kilobytes', 10240);

        if ($file->getSize() > $maxKilobytes * 1024) {
            $megabytes = round($maxKilobytes / 1024, 1);

            throw new DomainException(
                "This file is larger than the {$megabytes}MB limit.",
                ['file' => ["The document must not be larger than {$megabytes}MB."]],
            );
        }

        if ($file->getSize() === 0) {
            throw new DomainException(
                'This file is empty.',
                ['file' => ['The document appears to be empty.']],
            );
        }

        $mimeType = $this->detectMimeType($file);

        /** @var array<int, string> $allowed */
        $allowed = config('naipay.documents.allowed_mime_types', []);

        if (! in_array($mimeType, $allowed, true)) {
            throw new DomainException(
                'That file type is not accepted. Upload a PDF or an image.',
                ['file' => ['Accepted formats are PDF, JPEG, PNG, WebP and HEIC.']],
            );
        }
    }

    /**
     * Determines the type from the file's own bytes.
     */
    private function detectMimeType(UploadedFile $file): string
    {
        // Laravel's getMimeType() uses finfo against the contents rather than
        // the client-supplied header.
        $detected = $file->getMimeType() ?? 'application/octet-stream';

        // Cross-checked against the magic bytes: finfo can be permissive with
        // container formats, and a mismatch means the file is not what it
        // claims regardless of which source is right.
        if (! $this->matchesSignature($file, $detected)) {
            throw new DomainException(
                'That file does not appear to be the type it claims.',
                ['file' => ['The file contents do not match its format.']],
            );
        }

        return $detected;
    }

    private function matchesSignature(UploadedFile $file, string $mimeType): bool
    {
        $signatures = self::SIGNATURES[$mimeType] ?? null;

        // A type outside the accepted list is rejected by the allow-list check
        // regardless, so there is nothing to verify here.
        if ($signatures === null) {
            return true;
        }

        $handle = @fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            return false;
        }

        // Far enough in to cover HEIC, whose brand sits after a length prefix.
        $header = (string) fread($handle, 32);
        fclose($handle);

        foreach ($signatures as $signature) {
            if (str_contains($header, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function extensionFor(string $mimeType): string
    {
        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            default => 'bin',
        };
    }

    /**
     * Makes an uploaded filename safe to echo back.
     *
     * Only ever used for display and the download filename, never for a path.
     */
    private function sanitiseDisplayName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\P{C}]/u', '', $name) ?? $name;
        $name = str_replace(['/', '\\', "\0"], '', $name);

        return mb_substr(trim($name), 0, 255) ?: 'document';
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('naipay.documents.disk', 'documents'));
    }
}
