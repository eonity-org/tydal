<?php

namespace App\Services\Processing;

use GdImage;

/** Read PNG eXIf orientation, which PHP's JPEG/TIFF EXIF reader does not support. */
class PngOrientation
{
    public function read(string $data): int
    {
        if (! str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            return 1;
        }
        $length = strlen($data);
        for ($offset = 8; $offset + 12 <= $length;) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            if ($size > $length - $offset - 12) {
                return 1;
            }
            if (substr($data, $offset + 4, 4) === 'eXIf') {
                return $this->readTiff(substr($data, $offset + 8, $size));
            }
            $offset += $size + 12;
        }

        return 1;
    }

    private function readTiff(string $data): int
    {
        if (strlen($data) < 8 || ! in_array(substr($data, 0, 4), ["II\x2a\x00", "MM\x00\x2a"], true)) {
            return 1;
        }
        $short = str_starts_with($data, 'II') ? 'v' : 'n';
        $long = $short === 'v' ? 'V' : 'N';
        $offset = unpack($long, substr($data, 4, 4))[1];
        if ($offset > strlen($data) - 2) {
            return 1;
        }
        $count = unpack($short, substr($data, $offset, 2))[1];
        for ($i = 0, $offset = $offset + 2; $i < $count && $offset + 12 <= strlen($data); $i++, $offset += 12) {
            $tag = unpack($short, substr($data, $offset, 2))[1];
            $type = unpack($short, substr($data, $offset + 2, 2))[1];
            $items = unpack($long, substr($data, $offset + 4, 4))[1];
            if ($tag === 0x0112 && $type === 3 && $items === 1) {
                $value = unpack($short, substr($data, $offset + 8, 2))[1];

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /** Read the same TIFF orientation from a JPEG APP1 segment before re-encoding. */
    public function readJpeg(string $data): int
    {
        if (! str_starts_with($data, "\xFF\xD8")) {
            return 1;
        }
        $length = strlen($data);
        for ($offset = 2; $offset + 4 <= $length;) {
            if ($data[$offset++] !== "\xFF") {
                break;
            }
            while ($offset < $length && $data[$offset] === "\xFF") {
                $offset++;
            }
            if ($offset + 3 > $length) {
                break;
            }
            $marker = ord($data[$offset++]);
            if (in_array($marker, [0xDA, 0xD9], true)) {
                break;
            }
            $size = unpack('n', substr($data, $offset, 2))[1];
            if ($size < 2 || $size > $length - $offset) {
                break;
            }
            if ($marker === 0xE1 && $size >= 8 && substr($data, $offset + 2, 6) === "Exif\x00\x00") {
                return $this->readTiff(substr($data, $offset + 8, $size - 8));
            }
            $offset += $size;
        }

        return 1;
    }

    /** The caller owns the returned image; rotations release the replaced image. */
    public function apply(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        $degrees = match ($orientation) {
            3, 4 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };
        if ($degrees !== 0) {
            $rotated = imagerotate($image, $degrees, imagecolorallocatealpha($image, 0, 0, 0, 127));
            if ($rotated === false) {
                throw new \RuntimeException('Could not apply image orientation.');
            }
            imagedestroy($image);
            $image = $rotated;
        }
        imagesavealpha($image, true);

        return $image;
    }
}
