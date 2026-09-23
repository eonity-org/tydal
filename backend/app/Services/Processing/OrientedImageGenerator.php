<?php

namespace App\Services\Processing;

use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Image;

class OrientedImageGenerator extends Image
{
    public function convert(string $path, ?Conversion $conversion = null): string
    {
        $data = file_get_contents($path);
        $normalizer = new PngOrientation;
        $orientation = str_starts_with($data, "\xFF\xD8")
            ? $normalizer->readJpeg($data)
            : $normalizer->read($data);
        if ($orientation === 1) {
            return parent::convert($path, $conversion);
        }
        $image = imagecreatefromstring($data);
        if ($image === false) {
            throw new \RuntimeException('Could not decode image for orientation.');
        }
        $image = $normalizer->apply($image, $orientation);
        // MediaLibrary supplies a temporary working directory. Never overwrite the original.
        $output = $path.'.oriented.png';
        try {
            if (! imagepng($image, $output)) {
                throw new \RuntimeException('Could not save oriented PNG preview source.');
            }
        } finally {
            imagedestroy($image);
        }

        return $output;
    }
}
