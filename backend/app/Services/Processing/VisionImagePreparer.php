<?php

namespace App\Services\Processing;

use RuntimeException;

class VisionImagePreparer
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MIN_REQUEST_BYTES = 64 * 1024;

    public const MAX_REQUEST_BYTES = 20 * 1024 * 1024;

    public const SUPPORTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Create an in-memory vision copy without modifying the stored original. */
    public function prepare(string $data, string $mimeType, int $maxBytes = self::MAX_BYTES): array
    {
        if ($maxBytes < self::MIN_REQUEST_BYTES || $maxBytes > self::MAX_REQUEST_BYTES) {
            throw new \InvalidArgumentException('Vision image byte limit must be between 64 KiB and 20 MiB.');
        }
        $normalizer = new PngOrientation;
        $orientation = $normalizer->read($data);
        if (strlen($data) <= $maxBytes && $orientation === 1) {
            return ['data' => $data, 'mime_type' => $mimeType];
        }

        if ($mimeType === 'image/jpeg') {
            $orientation = $normalizer->readJpeg($data);
        }
        if (@getimagesizefromstring($data) === false) {
            throw new RuntimeException('Could not decode oversized image for vision.');
        }

        $source = @imagecreatefromstring($data);
        if ($source === false) {
            throw new RuntimeException('Could not decode oversized image for vision.');
        }

        $source = $normalizer->apply($source, $orientation);

        try {
            // Preserve all pixels when high-quality JPEG encoding alone fits.
            // Reduce dimensions gradually only when the encoded payload is too large.
            $originalEdge = max(imagesx($source), imagesy($source));
            for ($edge = $originalEdge; $edge >= 1; $edge = (int) floor($edge * 0.85)) {
                $scale = min(1, $edge / max(imagesx($source), imagesy($source)));
                $width = max(1, (int) round(imagesx($source) * $scale));
                $height = max(1, (int) round(imagesy($source) * $scale));
                $copy = imagecreatetruecolor($width, $height);
                try {
                    // Composite transparency onto white before JPEG encoding.
                    imagefill($copy, 0, 0, imagecolorallocate($copy, 255, 255, 255));
                    imagecopyresampled($copy, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
                    ob_start();
                    try {
                        $encoded = imagejpeg($copy, null, 95);
                        $jpeg = ob_get_contents();
                    } finally {
                        ob_end_clean();
                    }
                    if ($encoded && is_string($jpeg) && strlen($jpeg) > 0 && strlen($jpeg) <= $maxBytes) {
                        return ['data' => $jpeg, 'mime_type' => 'image/jpeg'];
                    }
                } finally {
                    imagedestroy($copy);
                }
            }
        } finally {
            imagedestroy($source);
        }

        throw new RuntimeException('Could not reduce vision image below the requested byte limit.');
    }
}
