<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FileStorageService
{
    /**
     * Store a non-media file (PDF, ZIP, etc.) for a resource.
     * Files are organized by resource ID: {resource_id}/archives/{filename}
     *
     * @param  string  $purpose
     * @return array{path: string, url: string, filename: string, size: int, mime_type: string}
     */
    public static function storeDocumentFile(
        string $resourceId,
        UploadedFile $file,
        string $disk = 'public'
    ): array {
        // Generate safe filename
        $filename = self::generateSafeFilename($file->getClientOriginalName());
        $path = "{$resourceId}/archives/{$filename}";

        // Store the file
        Storage::disk($disk)->put($path, file_get_contents($file));

        return [
            'path' => $path,
            'filename' => $filename,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'disk' => $disk,
            'metadata' => [
                'original_filename' => $file->getClientOriginalName(),
            ],
        ];
    }

    /**
     * Get the storage path for a document file.
     */
    public static function getDocumentPath(string $resourceId, string $filename): string
    {
        return "{$resourceId}/archives/".basename($filename);
    }

    /**
     * Generate a safe filename (prevent directory traversal).
     */
    protected static function generateSafeFilename(string $filename): string
    {
        // Get the base name to prevent directory traversal
        $basename = basename($filename);

        // Add timestamp prefix to prevent conflicts
        $extension = pathinfo($basename, PATHINFO_EXTENSION);
        $nameWithoutExt = pathinfo($basename, PATHINFO_FILENAME);
        $timestamp = now()->format('Y-m-d-His');

        // Sanitize the filename
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $nameWithoutExt);

        return "{$timestamp}-{$safeName}.{$extension}";
    }

    /**
     * Store extracted text as a gzip-compressed JSON file for a resource.
     *
     * @param  string  $jsonContent  Raw JSON string of chunk objects
     * @return array{path: string, filename: string, size: int, mime_type: string, disk: string}
     */
    /**
     * Store extracted text as a gzip-compressed JSON archive.
     *
     * Path is scoped to source file to avoid collisions when a resource
     * has multiple text-bearing files (e.g. two PDFs).
     *
     * @param  string  $sourceFileId  ID of the user-uploaded File that was extracted
     * @param  string  $jsonContent  JSON-encoded array of chunk objects
     * @return array{path: string, filename: string, size: int, mime_type: string, disk: string}
     */
    public static function storeExtractedText(
        string $resourceId,
        string $sourceFileId,
        string $jsonContent,
        string $disk = 'local'
    ): array {
        $gz = gzencode($jsonContent, 6);
        $path = "{$resourceId}/archives/{$sourceFileId}.json.gz";
        Storage::disk($disk)->put($path, $gz);

        return [
            'path' => $path,
            'filename' => 'extracted_text.json.gz',
            'size' => strlen($gz),
            'mime_type' => 'application/gzip',
            'disk' => $disk,
        ];
    }

    /**
     * Read and decompress a stored extracted-text archive.
     *
     * @return array<int, mixed>|null Decoded chunk array, or null if not found / corrupt
     */
    public static function readExtractedText(string $path, string $disk = 'local'): ?array
    {
        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $raw = gzdecode(Storage::disk($disk)->get($path));

        return $raw ? json_decode($raw, true) : null;
    }

    /**
     * Write an AI suggestion as a plain JSON archive in the shared archives/ directory.
     *
     * The $suffix determines the filename — e.g. 'suggested_tags', 'suggested_name',
     * 'suggested_description'. Not gzip-encoded: payloads are tiny (< 5 KB).
     *
     * @param  mixed  $data  Any JSON-serialisable value
     * @return array{path: string, filename: string, size: int, mime_type: string, disk: string}
     */
    public static function storeAiSuggestion(
        string $resourceId,
        string $sourceFileId,
        string $suffix,
        mixed $data,
        string $disk = 'local'
    ): array {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $path = "{$resourceId}/archives/{$sourceFileId}_{$suffix}.json";
        Storage::disk($disk)->put($path, $json);

        return [
            'path' => $path,
            'filename' => "{$suffix}.json",
            'size' => strlen($json),
            'mime_type' => 'application/json',
            'disk' => $disk,
        ];
    }

    /**
     * Persist a resource-scoped AITY synthesis output (no source file).
     *
     * The filename carries both a human-readable timestamp (for ordering in `ls`) and a
     * unique $signature. Two AITY processes can run against the same resource concurrently
     * (the same resource may sit in multiple review batches, or a job may be retried), and
     * the millisecond timestamp alone is not collision-proof — without the signature, two
     * writes in the same millisecond would clobber each other on disk. Callers pass the
     * SystemFile's UUID so the archive is also 1:1 traceable to its DB row.
     */
    public static function storeResourceAiSuggestion(
        string $resourceId,
        string $suffix,
        mixed $data,
        string $signature,
        string $disk = 'local'
    ): array {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $stamp = now()->format('YmdHisv');
        $path = "{$resourceId}/archives/generated_{$suffix}_{$stamp}_{$signature}.json";
        Storage::disk($disk)->put($path, $json);

        return [
            'path' => $path,
            'filename' => "generated_{$suffix}.json",
            'size' => strlen($json),
            'mime_type' => 'application/json',
            'disk' => $disk,
        ];
    }

    /**
     * Delete a file from storage.
     */
    public static function deleteFile(string $path, string $disk = 'public'): bool
    {
        return Storage::disk($disk)->delete($path);
    }

    /**
     * Check if a file exists.
     */
    public static function fileExists(string $path, string $disk = 'public'): bool
    {
        return Storage::disk($disk)->exists($path);
    }
}
