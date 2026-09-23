<?php

namespace Database\Seeders;

use App\Models\File;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Resource Media Seeder
 *
 * Attaches generated media files to the three known seed resources:
 *   - Mountain Landscape → 2 JPEG images (preview set to first)
 *   - Ocean Waves        → 1 MP4 video
 *   - Forest Birds       → 1 WAV audio
 */
class ResourceImageSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('=====================================');
        $this->command->info('Resource Media Seeder');
        $this->command->info('=====================================');
        $this->command->newLine();

        $superadmin = User::where('email', 'superadmin@tydal.test')->first();
        if (! $superadmin) {
            $this->command->error('Superadmin not found. Run MinimalSeeder first.');

            return;
        }

        $this->seedImages($superadmin);
        $this->seedVideo($superadmin);
        $this->seedAudio($superadmin);

        $this->command->newLine();
        $this->command->info('=====================================');
        $this->command->info('Done.');
        $this->command->info('=====================================');
    }

    // -------------------------------------------------------------------------

    private function seedImages(User $superadmin): void
    {
        $resource = Resource::where('slug', 'mountain-landscape')->first()
            ?? Resource::first();

        if (! $resource) {
            $this->command->error('No image resource found.');

            return;
        }

        $this->command->info("Images → {$resource->name}");

        $images = [
            ['name' => 'sunset-beach',  'filename' => 'sunset-beach.jpg',  'description' => 'Tropical beach sunset',    'color' => [255, 150, 100]],
            ['name' => 'city-skyline',  'filename' => 'city-skyline.jpg',   'description' => 'City skyline at night',    'color' => [50,  50,  150]],
        ];

        $firstFile = null;

        foreach ($images as $imageData) {
            $path = $this->generateImage($imageData);
            if (! $path) {
                $this->command->error("  ✗ Failed to generate {$imageData['name']}");

                continue;
            }

            $file = $this->attachMedia($resource, $superadmin, $path, $imageData['filename'], 'image/jpeg', $imageData['description']);
            $this->command->info("  ✓ {$imageData['name']} (File: {$file->id})");

            if ($firstFile === null) {
                $firstFile = $file;
                $file->update(['usage' => ['snapshot']]);
                $this->command->info("  ✓ Snapshot set to: {$imageData['name']}");
            }
        }
    }

    private function seedVideo(User $superadmin): void
    {
        $resource = Resource::where('slug', 'ocean-waves')->first();
        if (! $resource) {
            $this->command->warn('Video resource (ocean-waves) not found — skipping.');

            return;
        }

        $this->command->info("Video  → {$resource->name}");

        $path = $this->generateVideo('ocean-waves.mp4');
        if (! $path) {
            $this->command->error('  ✗ Failed to generate video.');

            return;
        }

        $file = $this->attachMedia($resource, $superadmin, $path, 'ocean-waves.mp4', 'video/mp4', 'Ocean waves footage');
        $this->command->info("  ✓ ocean-waves.mp4 (File: {$file->id})");
    }

    private function seedAudio(User $superadmin): void
    {
        $resource = Resource::where('slug', 'forest-birds')->first();
        if (! $resource) {
            $this->command->warn('Audio resource (forest-birds) not found — skipping.');

            return;
        }

        $this->command->info("Audio  → {$resource->name}");

        $path = $this->generateWav('forest-birds.wav');
        if (! $path) {
            $this->command->error('  ✗ Failed to generate audio.');

            return;
        }

        $file = $this->attachMedia($resource, $superadmin, $path, 'forest-birds.wav', 'audio/wav', 'Forest bird sounds');
        $this->command->info("  ✓ forest-birds.wav (File: {$file->id})");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function attachMedia(Resource $resource, User $owner, string $filePath, string $filename, string $mimeType, string $description): File
    {
        $media = $resource
            ->addMedia($filePath)
            ->usingFileName($filename)
            ->withCustomProperties(['description' => $description, 'generated' => true])
            ->toMediaCollection('files');

        return File::create([
            'id' => Str::uuid(),
            'resource_id' => $resource->id,
            'user_owner_id' => $owner->id,
            'media_id' => $media->id,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => $media->size,
            'role' => 'canonical',
            'relation' => null,
            'path' => $media->getPathRelativeToRoot(),
            'disk' => $media->disk,
            'metadata' => ['description' => $description, 'generated' => true],
            'is_active' => true,
        ]);
    }

    // ── Image (GD) ────────────────────────────────────────────────────────────

    private function generateImage(array $imageData, int $width = 1920, int $height = 1080): ?string
    {
        if (! extension_loaded('gd')) {
            $this->command->error('GD extension is not available.');

            return null;
        }

        $image = imagecreatetruecolor($width, $height);
        if (! $image) {
            return null;
        }

        [$r, $g, $b] = $imageData['color'];
        for ($y = 0; $y < $height; $y++) {
            $f = $y / $height;
            $color = imagecolorallocate($image,
                (int) ($r * (1 - $f * 0.5)),
                (int) ($g * (1 - $f * 0.3)),
                (int) ($b + (255 - $b) * $f * 0.5)
            );
            imageline($image, 0, $y, $width, $y, $color);
        }

        for ($i = 0; $i < 20; $i++) {
            $sc = imagecolorallocatealpha($image, rand(200, 255), rand(200, 255), rand(200, 255), 50);
            $x1 = rand(0, $width);
            $y1 = rand(0, $height);
            switch (rand(0, 2)) {
                case 0:
                    imagefilledellipse($image, $x1, $y1, rand(20, 150), rand(20, 150), $sc);
                    break;
                case 1:
                    imagefilledrectangle($image, $x1, $y1, rand(0, $width), rand(0, $height), $sc);
                    break;
                case 2:
                    imagesetthickness($image, rand(2, 10));
                    imageline($image, $x1, $y1, rand(0, $width), rand(0, $height), $sc);
                    break;
            }
        }

        $path = sys_get_temp_dir().'/'.$imageData['filename'];
        imagejpeg($image, $path, 85);
        imagedestroy($image);

        return $path;
    }

    // ── Video (minimal valid MP4 — ftyp + mdat boxes) ────────────────────────

    private function generateVideo(string $filename): ?string
    {
        // Minimal MP4: ftyp box (isom) + empty mdat box
        // Not playable but structurally valid for storage/MIME purposes
        $ftyp = pack('N', 32).'ftyp'.'isom'.pack('N', 0).'isomiso2avc1mp41';
        $mdat = pack('N', 8).'mdat';

        $path = sys_get_temp_dir().'/'.$filename;
        file_put_contents($path, $ftyp.$mdat);

        return $path;
    }

    // ── Audio (minimal valid WAV — 1 second of silence) ──────────────────────

    private function generateWav(string $filename): ?string
    {
        $sampleRate = 44100;
        $numChannels = 1;
        $bitsPerSample = 16;
        $duration = 3; // seconds
        $numSamples = $sampleRate * $duration;
        $byteRate = $sampleRate * $numChannels * ($bitsPerSample / 8);
        $blockAlign = $numChannels * ($bitsPerSample / 8);
        $dataSize = $numSamples * $blockAlign;

        $header = 'RIFF'
            .pack('V', 36 + $dataSize)   // ChunkSize
            .'WAVE'
            .'fmt '
            .pack('V', 16)               // Subchunk1Size (PCM)
            .pack('v', 1)                // AudioFormat   (PCM = 1)
            .pack('v', $numChannels)
            .pack('V', $sampleRate)
            .pack('V', $byteRate)
            .pack('v', $blockAlign)
            .pack('v', $bitsPerSample)
            .'data'
            .pack('V', $dataSize);

        $path = sys_get_temp_dir().'/'.$filename;
        file_put_contents($path, $header.str_repeat("\x00\x00", $numSamples));

        return $path;
    }
}
