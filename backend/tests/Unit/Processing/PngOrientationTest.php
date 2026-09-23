<?php

namespace Tests\Unit\Processing;

use App\Services\Processing\OrientedImageGenerator;
use App\Services\Processing\PngOrientation;
use App\Services\Processing\VisionImagePreparer;
use PHPUnit\Framework\TestCase;

class PngOrientationTest extends TestCase
{
    private function png(bool $littleEndian = false): string
    {
        $image = imagecreatetruecolor(30, 20);
        imagefilledrectangle($image, 0, 0, 14, 19, 0xFF0000);
        imagefilledrectangle($image, 15, 0, 29, 19, 0x0000FF);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $tiff = $littleEndian
            ? 'II'.pack('vVvvvVv', 42, 8, 1, 0x0112, 3, 1, 6)."\0\0".pack('V', 0)
            : 'MM'.pack('nNnnnNn', 42, 8, 1, 0x0112, 3, 1, 6)."\0\0".pack('N', 0);
        $chunk = 'eXIf'.$tiff;

        return substr($png, 0, 33).pack('N', strlen($tiff)).$chunk.pack('N', crc32($chunk)).substr($png, 33);
    }

    /** Same red-left/blue-right source as png(), as a JPEG with an EXIF orientation=6 APP1 segment. */
    private function jpeg(): string
    {
        $image = imagecreatetruecolor(30, 20);
        imagefilledrectangle($image, 0, 0, 14, 19, 0xFF0000);
        imagefilledrectangle($image, 15, 0, 29, 19, 0x0000FF);
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $tiff = 'MM'.pack('nNnnnNn', 42, 8, 1, 0x0112, 3, 1, 6)."\0\0".pack('N', 0);
        $exif = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    }

    public function test_reads_both_exif_byte_orders_and_rejects_truncated_chunks(): void
    {
        $reader = new PngOrientation;
        $this->assertSame(6, $reader->read($this->png()));
        $this->assertSame(6, $reader->read($this->png(true)));
        $this->assertSame(1, $reader->read(substr($this->png(), 0, 45)));
    }

    public function test_vision_rotates_even_small_pngs_clockwise(): void
    {
        $result = (new VisionImagePreparer)->prepare($this->png(), 'image/png');
        $image = imagecreatefromstring($result['data']);
        $this->assertSame(20, imagesx($image));
        $this->assertSame(30, imagesy($image));
        $top = imagecolorsforindex($image, imagecolorat($image, 10, 5));
        $bottom = imagecolorsforindex($image, imagecolorat($image, 10, 25));
        $this->assertGreaterThan(240, $top['red']);
        $this->assertGreaterThan(240, $bottom['blue']);
        imagedestroy($image);
    }

    public function test_preview_generator_preserves_source_and_does_not_rotate_twice(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'orientation-test-');
        $output = $path.'.oriented.png';
        try {
            $png = $this->png();
            file_put_contents($path, $png);
            $generator = new OrientedImageGenerator;
            $this->assertSame($output, $generator->convert($path));
            $this->assertSame($png, file_get_contents($path));
            $info = getimagesize($output);
            $this->assertSame(20, $info[0]);
            $this->assertSame(30, $info[1]);
            $this->assertSame($output, $generator->convert($output));
        } finally {
            unlink($path);
            if (is_file($output)) {
                unlink($output);
            }
        }
    }

    public function test_preview_generator_rotates_jpeg_sources_too(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'orientation-test-');
        $output = $path.'.oriented.png';
        try {
            file_put_contents($path, $this->jpeg());
            $generator = new OrientedImageGenerator;
            $this->assertSame($output, $generator->convert($path));
            $info = getimagesize($output);
            $this->assertSame(20, $info[0]);
            $this->assertSame(30, $info[1]);
            $image = imagecreatefromstring(file_get_contents($output));
            $top = imagecolorsforindex($image, imagecolorat($image, 10, 5));
            $bottom = imagecolorsforindex($image, imagecolorat($image, 10, 25));
            $this->assertGreaterThan(200, $top['red']);
            $this->assertGreaterThan(200, $bottom['blue']);
            imagedestroy($image);
        } finally {
            unlink($path);
            if (is_file($output)) {
                unlink($output);
            }
        }
    }
}
