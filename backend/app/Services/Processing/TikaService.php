<?php

namespace App\Services\Processing;

use App\Models\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class TikaService
{
    /**
     * MIME types from which Tika can extract meaningful text.
     * These files get full text extraction + chunking.
     */
    private const TEXT_EXTRACTABLE = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.presentation',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.spreadsheet',
        'text/plain',
        'text/markdown',
        'text/html',
        'text/rtf',
        'application/rtf',
        'application/epub+zip',
    ];

    /**
     * MIME prefixes for which Tika extracts metadata only (EXIF, ID3, etc.)
     * No text content, no chunking.
     */
    private const METADATA_ONLY_PREFIXES = ['image/', 'audio/', 'video/'];

    private string $tikaUrl;

    private int $timeout;

    public function __construct()
    {
        $this->tikaUrl = rtrim(config('tika.url', 'http://localhost:9998'), '/');
        $this->timeout = config('tika.timeout', 120);
    }

    // =========================================================================
    // EXTRACTION METHODS
    // =========================================================================

    /**
     * Extract structured XHTML and plain text from a document.
     *
     * Tika 3 and earlier expose XHTML from /tika when text/html is requested.
     * Tika 4 no longer negotiates that representation at this endpoint and
     * returns 406; for that version we fall back to its text/plain output.
     *
     * @return array{xhtml: string, plain_text: string, char_count: int}
     *
     * @throws \RuntimeException on Tika connectivity failure
     */
    public function extractText(string $absolutePath): array
    {
        // XHTML output preserves structure (page divs, paragraphs, slide divs)
        // used by ChunkingService for natural chunk boundaries. Tika 4 only
        // advertises text/plain here, so retain compatibility with both APIs.
        try {
            $xhtml = $this->callTika('/tika', $absolutePath, 'text/html');
        } catch (\RuntimeException $e) {
            if (! str_contains($e->getMessage(), 'HTTP 406')) {
                throw $e;
            }

            $xhtml = $this->callTika('/tika', $absolutePath, 'text/plain');
        }

        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($xhtml)));

        return [
            'xhtml' => $xhtml,
            'plain_text' => $plain,
            'char_count' => strlen($plain),
        ];
    }

    /**
     * Extract file metadata (EXIF, ID3, document properties) from any file.
     *
     * @return array<string, mixed> flat key→value map from Tika /meta
     *
     * @throws \RuntimeException on Tika connectivity failure
     */
    public function extractMetadata(string $absolutePath): array
    {
        $json = $this->callTika('/meta', $absolutePath, 'application/json');
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    // =========================================================================
    // MIME TYPE ROUTING
    // =========================================================================

    public function isTextExtractable(string $mimeType): bool
    {
        return in_array($mimeType, self::TEXT_EXTRACTABLE, true);
    }

    public function isMetadataExtractable(string $mimeType): bool
    {
        foreach (self::METADATA_ONLY_PREFIXES as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // PATH RESOLUTION
    // =========================================================================

    /**
     * Resolve an absolute filesystem path for a File record.
     *
     * For local/public disks: returns the real path directly.
     * For remote disks (S3/MinIO): streams the file to a temp location.
     *
     * The caller MUST unlink the path when temp=true.
     *
     * @return array{path: string, temp: bool}
     */
    public function resolveAbsolutePath(File $file): array
    {
        $disk = $file->disk ?? 'local';

        if (in_array($disk, ['local', 'public'], true)) {
            return [
                'path' => Storage::disk($disk)->path($file->path),
                'temp' => false,
            ];
        }

        // Remote disk — stream to temp file
        $tmp = tempnam(sys_get_temp_dir(), 'tydal_tika_');
        $stream = Storage::disk($disk)->readStream($file->path);
        $dest = fopen($tmp, 'wb');
        stream_copy_to_stream($stream, $dest);
        fclose($dest);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return ['path' => $tmp, 'temp' => true];
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    /**
     * POST the file binary to a Tika endpoint and return the response body.
     *
     * NOTE: file_get_contents is used for simplicity. For files > 50 MB,
     * consider switching to a streamed Guzzle request to avoid memory spikes.
     *
     * @throws \RuntimeException
     */
    private function callTika(string $endpoint, string $filePath, string $accept): string
    {
        $response = Http::withOptions(['timeout' => $this->timeout])
            ->withBody(file_get_contents($filePath), 'application/octet-stream')
            ->withHeaders(['Accept' => $accept])
            ->put($this->tikaUrl.$endpoint);

        if ($response->failed()) {
            throw new \RuntimeException(
                "Tika {$endpoint} returned HTTP {$response->status()}: ".$response->body()
            );
        }

        return $response->body();
    }
}
