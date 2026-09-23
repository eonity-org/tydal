<?php

namespace App\Jobs;

use App\Enums\SystemFilePurpose;
use App\Models\File;
use App\Models\Resource;
use App\Models\SystemFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Extracts an embedded preview image from a source file and stores it
 * as a SystemFile(purpose=PREVIEW_SNAPSHOT) at a fixed path so that
 * re-starring the same file overwrites rather than accumulates dated files.
 *
 * Supports:
 * - Audio files: extracts APIC album art from ID3v2 tags via getid3
 * - PDF files:   renders first page via pdftoppm / convert (ImageMagick) / ffmpeg
 *                (skips gracefully if no rendering tool is installed)
 *
 * Skips when a different (image) file is already the snapshot.
 */
class ExtractEmbeddedPreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(private readonly string $fileId) {}

    // =========================================================================

    public function handle(): void
    {
        $file = File::with('resource')->find($this->fileId);

        if (! $file || ! $file->resource) {
            return;
        }

        // Skip extraction if a snapshot already exists AND it is not the source file itself.
        // When the source file is the snapshot (user starred a PDF/audio file), we should
        // proceed so we can create a rendered image and promote it to snapshot.
        $hasOtherSnapshot = $file->resource->files()
            ->whereJsonContains('usage', 'snapshot')
            ->where('is_active', true)
            ->where('id', '!=', $file->id)
            ->exists();

        if ($hasOtherSnapshot) {
            return;
        }

        [$imageBytes, $mimeType] = $this->extractPreview($file);

        if (! $imageBytes) {
            return;
        }

        $this->savePreview($file->resource, $file, $imageBytes, $mimeType);
    }

    // =========================================================================
    // EXTRACTION DISPATCH
    // =========================================================================

    /** @return array{0: string|null, 1: string|null} */
    private function extractPreview(File $file): array
    {
        if ($file->isAudio()) {
            return $this->extractAudioAlbumArt($file);
        }

        if ($file->mime_type === 'application/pdf') {
            return $this->extractPdfFirstPage($file);
        }

        return [null, null];
    }

    // =========================================================================
    // AUDIO: ID3v2 APIC frame via getid3
    // =========================================================================

    /** @return array{0: string|null, 1: string|null} */
    private function extractAudioAlbumArt(File $file): array
    {
        $absolutePath = $this->absolutePath($file);
        if (! $absolutePath || ! file_exists($absolutePath)) {
            return [null, null];
        }

        try {
            $getid3 = new \getID3;
            $info = $getid3->analyze($absolutePath);

            // getid3 puts APIC frames under id3v2.APIC (indexed array)
            $frames = $info['id3v2']['APIC'] ?? [];

            foreach ($frames as $frame) {
                $data = $frame['data'] ?? null;
                $mime = ($frame['image_mime'] ?? '') ?: 'image/jpeg';

                if ($data && strlen($data) > 0) {
                    return [$data, $mime];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("ExtractEmbeddedPreview: getid3 failed for file {$file->id}: ".$e->getMessage());
        }

        return [null, null];
    }

    // =========================================================================
    // PDF: first-page render via system tools
    // =========================================================================

    /** @return array{0: string|null, 1: string|null} */
    private function extractPdfFirstPage(File $file): array
    {
        $absolutePath = $this->absolutePath($file);
        if (! $absolutePath || ! file_exists($absolutePath)) {
            return [null, null];
        }

        // Try available rendering tools in preference order
        $data = $this->tryPdftoppm($absolutePath)
            ?? $this->tryConvert($absolutePath)
            ?? $this->tryFfmpeg($absolutePath);

        return $data ? [$data, 'image/jpeg'] : [null, null];
    }

    private function tryPdftoppm(string $path): ?string
    {
        $binary = $this->whichBinary('pdftoppm');
        if (! $binary) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tydal_pdf_');

        $cmd = sprintf(
            '%s -jpeg -r 150 -f 1 -l 1 %s %s 2>/dev/null',
            escapeshellarg($binary),
            escapeshellarg($path),
            escapeshellarg($tmp)
        );
        exec($cmd, $output, $code);

        // pdftoppm appends '-1.jpg' (zero-padded varies by version)
        $candidates = [$tmp.'-1.jpg', $tmp.'-01.jpg', $tmp.'-001.jpg'];
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $data = file_get_contents($candidate) ?: null;
                unlink($candidate);
                @unlink($tmp);

                return $data;
            }
        }

        @unlink($tmp);

        return null;
    }

    private function tryConvert(string $path): ?string
    {
        $binary = $this->whichBinary('convert');
        if (! $binary) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tydal_pdf_').'.jpg';

        $cmd = sprintf(
            '%s -density 150 %s[0] -quality 85 %s 2>/dev/null',
            escapeshellarg($binary),
            escapeshellarg($path),
            escapeshellarg($tmp)
        );
        exec($cmd, $output, $code);

        if ($code === 0 && file_exists($tmp)) {
            $data = file_get_contents($tmp) ?: null;
            unlink($tmp);

            return $data;
        }

        @unlink($tmp);

        return null;
    }

    private function tryFfmpeg(string $path): ?string
    {
        $binary = $this->whichBinary('ffmpeg');
        if (! $binary) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tydal_pdf_').'.jpg';

        // ffmpeg can render PDF pages when linked with Ghostscript / poppler
        $cmd = sprintf(
            '%s -i %s -vf "select=eq(n\\,0)" -vframes 1 %s -y 2>/dev/null',
            escapeshellarg($binary),
            escapeshellarg($path),
            escapeshellarg($tmp)
        );
        exec($cmd, $output, $code);

        if ($code === 0 && file_exists($tmp)) {
            $data = file_get_contents($tmp) ?: null;
            unlink($tmp);

            return $data;
        }

        @unlink($tmp);

        return null;
    }

    // =========================================================================
    // PERSIST
    // =========================================================================

    private function savePreview(Resource $resource, File $sourceFile, string $imageBytes, string $mimeType): void
    {
        $ext = $mimeType === 'image/png' ? 'png' : 'jpg';
        $filename = 'preview-snapshot.'.$ext;
        $path = "{$resource->id}/system/{$filename}";
        $disk = $sourceFile->disk ?? 'public';

        // Overwrite the file on disk (same fixed path every time).
        Storage::disk($disk)->put($path, $imageBytes);

        // Deactivate any previous preview snapshot system files for this resource.
        SystemFile::where('resource_id', $resource->id)
            ->where('purpose', SystemFilePurpose::PREVIEW_SNAPSHOT->value)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        SystemFile::create([
            'resource_id' => $resource->id,
            'source_file_id' => $sourceFile->id,
            'purpose' => SystemFilePurpose::PREVIEW_SNAPSHOT,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => strlen($imageBytes),
            'path' => $path,
            'disk' => $disk,
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Return the absolute filesystem path for the file's stored content,
     * or null if the file is on a remote disk (S3/MinIO) and streaming
     * is not yet needed (callers can extend this if needed).
     */
    private function absolutePath(File $file): ?string
    {
        $disk = $file->disk ?? 'public';

        if (! in_array($disk, ['local', 'public'], true)) {
            return null; // Remote disks not yet supported — skip silently
        }

        return Storage::disk($disk)->path($file->path);
    }

    private function whichBinary(string $name): ?string
    {
        $result = trim((string) shell_exec("which {$name} 2>/dev/null"));

        return $result !== '' ? $result : null;
    }
}
