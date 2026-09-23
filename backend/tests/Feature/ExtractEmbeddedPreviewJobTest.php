<?php

namespace Tests\Feature;

use App\Enums\FileRole;
use App\Enums\SystemFilePurpose;
use App\Jobs\ExtractEmbeddedPreview;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExtractEmbeddedPreviewJobTest extends TestCase
{
    use RefreshDatabase;

    private Resource $resource;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $org = Organization::factory()->create();
        $this->owner = User::factory()->create();
        $org->users()->attach($this->owner->id, ['role' => 'owner']);

        $scheme = CollectionScheme::create([
            'name' => 'test-scheme',
            'display_name' => 'Test',
            'accepted_mimetypes' => [],
            'is_system' => false,
            'fields' => [],
        ]);
        $collection = Collection::factory()->create([
            'organization_id' => $org->id,
            'user_owner_id' => $this->owner->id,
            'scheme_id' => $scheme->id,
        ]);
        $this->resource = Resource::factory()->create([
            'collection_id' => $collection->id,
            'organization_id' => $org->id,
            'user_owner_id' => $this->owner->id,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a minimal MP3 file with an embedded JPEG album art via ID3v2.
     * The binary is a real ID3v2.3 header with a single APIC frame.
     */
    private function seedAudioFileWithAlbumArt(): File
    {
        // Minimal 1×1 white JPEG
        $jpegBytes = base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwg'
            .'JC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIy'
            .'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFgAB'
            .'AQEAAAAAAAAAAAAAAAAABgUE/8QAHhAAAQQDAQEBAAAAAAAAAAAAAQACAxESITFB/8QAFAEBAAAAAAAAAA'
            .'AAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AKwABkAH/9k='
        );

        // Build a minimal ID3v2.3 APIC frame
        $mimeStr = "image/jpeg\x00"; // mime type string, null-terminated
        $pictureType = "\x03";           // 0x03 = Cover (front)
        $description = "\x00";           // empty description, null-terminated
        $frameData = $mimeStr.$pictureType.$description.$jpegBytes;

        // Text encoding byte (0x00 = ISO-8859-1) prepended to frame data
        $framePayload = "\x00".$frameData;

        // ID3v2 frame: "APIC" + 4-byte big-endian size + 2-byte flags
        $apicFrame = 'APIC'
            .pack('N', strlen($framePayload))
            ."\x00\x00"
            .$framePayload;

        // ID3v2.3 header: "ID3" + version(2.3) + revision(0) + flags(0) + syncsafe size
        $tagSize = strlen($apicFrame);
        $syncsafe = pack('N',
            (($tagSize >> 21) & 0x7F) << 24
            | (($tagSize >> 14) & 0x7F) << 16
            | (($tagSize >> 7) & 0x7F) << 8
            | ($tagSize & 0x7F)
        );
        $id3Header = "ID3\x03\x00\x00".$syncsafe;

        $mp3Content = $id3Header.$apicFrame;

        // Store in fake filesystem
        $path = "{$this->resource->id}/archives/test-audio.mp3";
        Storage::disk('public')->put($path, $mp3Content);

        return $this->resource->files()->create([
            'user_owner_id' => $this->owner->id,
            'filename' => 'test-audio.mp3',
            'mime_type' => 'audio/mpeg',
            'size' => strlen($mp3Content),
            'role' => FileRole::CANONICAL->value,
            'relation' => null,
            'disk' => 'public',
            'path' => $path,
            'is_active' => true,
        ]);
    }

    private function seedAudioFileWithoutAlbumArt(): File
    {
        // 4 bytes of valid MPEG sync + silence — no ID3 header
        $mp3Content = "\xFF\xFB\x90\x00".str_repeat("\x00", 100);
        $path = "{$this->resource->id}/archives/bare-audio.mp3";
        Storage::disk('public')->put($path, $mp3Content);

        return $this->resource->files()->create([
            'user_owner_id' => $this->owner->id,
            'filename' => 'bare-audio.mp3',
            'mime_type' => 'audio/mpeg',
            'size' => strlen($mp3Content),
            'role' => FileRole::CANONICAL->value,
            'relation' => null,
            'disk' => 'public',
            'path' => $path,
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // Guard conditions
    // =========================================================================

    public function test_does_nothing_when_file_not_found(): void
    {
        (new ExtractEmbeddedPreview('00000000-0000-0000-0000-000000000000'))->handle();

        $this->assertNull($this->resource->fresh()->snapshotFile);
    }

    public function test_does_nothing_when_snapshot_already_set(): void
    {
        $existingSnapshot = $this->resource->files()->create([
            'user_owner_id' => $this->owner->id,
            'filename' => 'cover.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 100,
            'role' => FileRole::SUPPORTING->value,
            'relation' => null,
            'usage' => ['snapshot'],
            'disk' => 'public',
            'path' => "{$this->resource->id}/archives/cover.jpg",
            'is_active' => true,
        ]);

        $file = $this->seedAudioFileWithAlbumArt();

        (new ExtractEmbeddedPreview($file->id))->handle();

        // Image snapshot file must remain — no preview system file should be created
        $this->assertSame(
            $existingSnapshot->id,
            $this->resource->fresh()->snapshotFile?->id
        );
        $this->assertNull($this->resource->fresh()->previewSnapshotSystemFile);
    }

    public function test_does_nothing_for_unsupported_mime_type(): void
    {
        $path = "{$this->resource->id}/archives/test.txt";
        Storage::disk('public')->put($path, 'hello');

        $file = $this->resource->files()->create([
            'user_owner_id' => $this->owner->id,
            'filename' => 'test.txt',
            'mime_type' => 'text/plain',
            'size' => 5,
            'role' => FileRole::CANONICAL->value,
            'relation' => null,
            'disk' => 'public',
            'path' => $path,
            'is_active' => true,
        ]);

        (new ExtractEmbeddedPreview($file->id))->handle();

        $this->assertNull($this->resource->fresh()->snapshotFile);
    }

    // =========================================================================
    // Audio album art extraction
    // =========================================================================

    public function test_audio_album_art_creates_preview_snapshot_system_file(): void
    {
        $file = $this->seedAudioFileWithAlbumArt();

        (new ExtractEmbeddedPreview($file->id))->handle();

        $this->assertNotNull($this->resource->fresh()->previewSnapshotSystemFile);
    }

    public function test_audio_album_art_preview_snapshot_has_correct_purpose(): void
    {
        $file = $this->seedAudioFileWithAlbumArt();

        (new ExtractEmbeddedPreview($file->id))->handle();

        $systemFile = $this->resource->fresh()->previewSnapshotSystemFile;
        $this->assertNotNull($systemFile);
        $this->assertSame(SystemFilePurpose::PREVIEW_SNAPSHOT->value, $systemFile->purpose->value);
        $this->assertSame($file->id, $systemFile->source_file_id);
    }

    public function test_audio_album_art_preview_snapshot_has_image_mime(): void
    {
        $file = $this->seedAudioFileWithAlbumArt();

        (new ExtractEmbeddedPreview($file->id))->handle();

        $systemFile = $this->resource->fresh()->previewSnapshotSystemFile;
        $this->assertStringStartsWith('image/', $systemFile->mime_type);
    }

    public function test_audio_album_art_preview_snapshot_uses_fixed_filename(): void
    {
        $file = $this->seedAudioFileWithAlbumArt();

        (new ExtractEmbeddedPreview($file->id))->handle();

        $systemFile = $this->resource->fresh()->previewSnapshotSystemFile;
        $this->assertStringStartsWith('preview-snapshot.', $systemFile->filename);
        $this->assertStringContainsString('/system/preview-snapshot.', $systemFile->path);
    }

    public function test_audio_album_art_does_not_create_extra_file_records(): void
    {
        $file = $this->seedAudioFileWithAlbumArt();
        $countBefore = $this->resource->files()->count();

        (new ExtractEmbeddedPreview($file->id))->handle();

        $this->assertSame($countBefore, $this->resource->files()->count());
    }

    public function test_rerunning_job_replaces_preview_snapshot_not_accumulates(): void
    {
        $file = $this->seedAudioFileWithAlbumArt();

        (new ExtractEmbeddedPreview($file->id))->handle();
        (new ExtractEmbeddedPreview($file->id))->handle();

        $activeCount = SystemFile::where('resource_id', $this->resource->id)
            ->where('purpose', SystemFilePurpose::PREVIEW_SNAPSHOT->value)
            ->where('is_active', true)
            ->count();

        $this->assertSame(1, $activeCount);
    }

    public function test_audio_without_album_art_leaves_preview_snapshot_null(): void
    {
        $file = $this->seedAudioFileWithoutAlbumArt();

        (new ExtractEmbeddedPreview($file->id))->handle();

        $this->assertNull($this->resource->fresh()->previewSnapshotSystemFile);
    }

    public function test_audio_preview_snapshot_is_stored_on_disk(): void
    {
        $file = $this->seedAudioFileWithAlbumArt();

        (new ExtractEmbeddedPreview($file->id))->handle();

        $systemFile = $this->resource->fresh()->previewSnapshotSystemFile;
        Storage::disk('public')->assertExists($systemFile->path);
    }

    // =========================================================================
    // PDF — graceful degradation (no rendering tools in test env)
    // =========================================================================

    public function test_pdf_without_rendering_tools_leaves_snapshot_null(): void
    {
        $path = "{$this->resource->id}/archives/test.pdf";
        Storage::disk('public')->put($path, '%PDF-1.4 fake content');

        $file = $this->resource->files()->create([
            'user_owner_id' => $this->owner->id,
            'filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'size' => 20,
            'role' => FileRole::CANONICAL->value,
            'relation' => null,
            'disk' => 'public',
            'path' => $path,
            'is_active' => true,
        ]);

        (new ExtractEmbeddedPreview($file->id))->handle();

        // In the test environment pdftoppm / convert / ffmpeg are not available,
        // so the job should complete without creating a preview snapshot.
        $this->assertNull($this->resource->fresh()->previewSnapshotSystemFile);
    }
}
