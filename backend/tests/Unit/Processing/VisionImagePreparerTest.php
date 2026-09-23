<?php

namespace Tests\Unit\Processing;

use App\Services\Processing\VisionImagePreparer;
use PHPUnit\Framework\TestCase;

class VisionImagePreparerTest extends TestCase
{
    public function test_small_images_are_preserved(): void
    {
        $this->assertSame(['data' => 'small-image', 'mime_type' => 'image/png'],
            (new VisionImagePreparer)->prepare('small-image', 'image/png'));
    }

    public function test_oversized_png_preserves_full_resolution_when_jpeg_fits(): void
    {
        $image = imagecreatetruecolor(2200, 2200);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 180, 220));
        ob_start();
        imagepng($image, null, 0);
        $original = ob_get_clean();
        imagedestroy($image);
        $this->assertGreaterThan(VisionImagePreparer::MAX_BYTES, strlen($original));

        $prepared = (new VisionImagePreparer)->prepare($original, 'image/png');
        $this->assertSame('image/jpeg', $prepared['mime_type']);
        $this->assertLessThanOrEqual(VisionImagePreparer::MAX_BYTES, strlen($prepared['data']));
        $info = getimagesizefromstring($prepared['data']);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertSame(2200, $info[0]);
        $this->assertSame(2200, $info[1]);
    }

    public function test_dimensions_are_reduced_only_when_full_resolution_jpeg_exceeds_limit(): void
    {
        $image = imagecreatetruecolor(2400, 2400);
        // Deterministic high-detail pixels produce a JPEG larger than the API limit.
        $state = 42;
        for ($y = 0; $y < 2400; $y++) {
            for ($x = 0; $x < 2400; $x++) {
                $state = ($state * 1664525 + 1013904223) & 0xFFFFFFFF;
                imagesetpixel($image, $x, $y, $state & 0xFFFFFF);
            }
        }
        ob_start();
        imagejpeg($image, null, 95);
        $original = ob_get_clean();
        imagedestroy($image);
        $this->assertGreaterThan(VisionImagePreparer::MAX_BYTES, strlen($original));

        $prepared = (new VisionImagePreparer)->prepare($original, 'image/jpeg');
        $this->assertLessThanOrEqual(VisionImagePreparer::MAX_BYTES, strlen($prepared['data']));
        $info = getimagesizefromstring($prepared['data']);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertLessThan(2400, $info[0]);
        $this->assertSame($info[0], $info[1]);
    }

    public function test_custom_budget_preserves_jpeg_orientation_when_reencoding(): void
    {
        $image = imagecreatetruecolor(640, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 180, 220));
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $tiff = "II\x2a\x00".pack('V', 8).pack('v', 1)
            .pack('vvVv', 0x0112, 3, 1, 6).str_repeat("\x00", 6);
        $exif = "Exif\x00\x00".$tiff;
        $padding = "\xFF\xFE".pack('n', 60002).str_repeat('x', 60000);
        $original = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif
            .$padding.$padding.substr($jpeg, 2);
        $preparer = new VisionImagePreparer;
        // A fitting original retains its bytes and EXIF, while a new JPEG needs physical rotation.
        $this->assertSame($original, $preparer->prepare($original, 'image/jpeg')['data']);
        $prepared = $preparer->prepare($original, 'image/jpeg', 65536);
        $this->assertLessThanOrEqual(65536, strlen($prepared['data']));
        $info = getimagesizefromstring($prepared['data']);
        $this->assertSame(400, $info[0]);
        $this->assertSame(640, $info[1]);
    }

    public function test_out_of_range_budget_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VisionImagePreparer)->prepare('small-image', 'image/png', 0);
    }

    public function test_invalid_oversized_image_throws_instead_of_marking_analysis_complete(): void
    {
        $this->expectException(\RuntimeException::class);
        (new VisionImagePreparer)->prepare(str_repeat('x', VisionImagePreparer::MAX_BYTES + 1), 'image/png');
    }
}
