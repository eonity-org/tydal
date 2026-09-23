<?php

namespace Tests\Unit\Processing;

use App\Models\File;
use App\Services\Processing\TikaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for TikaService.
 * HTTP calls are intercepted with Http::fake() — no real Tika server required.
 * RefreshDatabase ensures File::factory() records created in tests are rolled back.
 */
class TikaServiceTest extends TestCase
{
    use RefreshDatabase;
    // =========================================================================
    // MIME type routing
    // =========================================================================

    public function test_pdf_is_text_extractable(): void
    {
        $this->assertTrue((new TikaService)->isTextExtractable('application/pdf'));
    }

    public function test_docx_is_text_extractable(): void
    {
        $this->assertTrue((new TikaService)->isTextExtractable(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ));
    }

    public function test_plain_text_is_text_extractable(): void
    {
        $this->assertTrue((new TikaService)->isTextExtractable('text/plain'));
    }

    public function test_markdown_is_text_extractable(): void
    {
        $this->assertTrue((new TikaService)->isTextExtractable('text/markdown'));
    }

    public function test_image_is_not_text_extractable(): void
    {
        $this->assertFalse((new TikaService)->isTextExtractable('image/jpeg'));
    }

    public function test_audio_is_not_text_extractable(): void
    {
        $this->assertFalse((new TikaService)->isTextExtractable('audio/mpeg'));
    }

    public function test_zip_is_not_text_extractable(): void
    {
        $this->assertFalse((new TikaService)->isTextExtractable('application/zip'));
    }

    public function test_image_is_metadata_extractable(): void
    {
        $this->assertTrue((new TikaService)->isMetadataExtractable('image/jpeg'));
        $this->assertTrue((new TikaService)->isMetadataExtractable('image/png'));
        $this->assertTrue((new TikaService)->isMetadataExtractable('image/tiff'));
    }

    public function test_audio_is_metadata_extractable(): void
    {
        $this->assertTrue((new TikaService)->isMetadataExtractable('audio/mpeg'));
        $this->assertTrue((new TikaService)->isMetadataExtractable('audio/wav'));
    }

    public function test_video_is_metadata_extractable(): void
    {
        $this->assertTrue((new TikaService)->isMetadataExtractable('video/mp4'));
    }

    public function test_pdf_is_not_metadata_only(): void
    {
        $this->assertFalse((new TikaService)->isMetadataExtractable('application/pdf'));
    }

    // =========================================================================
    // extractText
    // =========================================================================

    public function test_extract_text_returns_plain_text_and_xhtml(): void
    {
        Http::fake(['*' => Http::response(
            '<html><body><p>Hello world from Tika.</p></body></html>',
            200
        )]);

        $path = $this->fakePdfOnDisk();

        $result = (new TikaService)->extractText($path);

        $this->assertArrayHasKey('xhtml', $result);
        $this->assertArrayHasKey('plain_text', $result);
        $this->assertArrayHasKey('char_count', $result);
        $this->assertSame('Hello world from Tika.', $result['plain_text']);
        $this->assertSame(22, $result['char_count']);
    }

    public function test_extract_text_sends_xhtml_accept_header(): void
    {
        Http::fake(['*' => Http::response('<html><body><p>content</p></body></html>', 200)]);

        $path = $this->fakePdfOnDisk();
        (new TikaService)->extractText($path);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/tika')
                && $request->header('Accept')[0] === 'text/html';
        });
    }

    public function test_extract_text_falls_back_to_plain_text_when_tika_rejects_xhtml(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push('', 406)
                ->push('Hello world from Tika.', 200),
        ]);

        $result = (new TikaService)->extractText($this->fakePdfOnDisk());

        $this->assertSame('Hello world from Tika.', $result['plain_text']);
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            return $request->header('Accept')[0] === 'text/plain';
        });
    }

    public function test_extract_text_collapses_whitespace_in_plain_text(): void
    {
        Http::fake(['*' => Http::response(
            '<html><body><p>word1    word2  word3</p></body></html>',
            200
        )]);

        $result = (new TikaService)->extractText($this->fakePdfOnDisk());

        $this->assertSame('word1 word2 word3', $result['plain_text']);
    }

    public function test_extract_text_throws_on_tika_error_response(): void
    {
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);

        $this->expectException(\RuntimeException::class);
        (new TikaService)->extractText($this->fakePdfOnDisk());
    }

    // =========================================================================
    // extractMetadata
    // =========================================================================

    public function test_extract_metadata_returns_flat_key_value_array(): void
    {
        $meta = ['Content-Type' => 'application/pdf', 'Author' => 'Jane', 'dc:title' => 'My Doc'];
        Http::fake(['*' => Http::response(json_encode($meta), 200)]);

        $result = (new TikaService)->extractMetadata($this->fakePdfOnDisk());

        $this->assertSame('application/pdf', $result['Content-Type']);
        $this->assertSame('Jane', $result['Author']);
    }

    public function test_extract_metadata_sends_json_accept_header(): void
    {
        Http::fake(['*' => Http::response('{}', 200)]);

        (new TikaService)->extractMetadata($this->fakePdfOnDisk());

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/meta')
                && $request->header('Accept')[0] === 'application/json';
        });
    }

    public function test_extract_metadata_returns_empty_array_on_invalid_json(): void
    {
        Http::fake(['*' => Http::response('not-json', 200)]);

        $result = (new TikaService)->extractMetadata($this->fakePdfOnDisk());

        $this->assertSame([], $result);
    }

    // =========================================================================
    // resolveAbsolutePath
    // =========================================================================

    public function test_resolve_absolute_path_local_disk_returns_no_temp_flag(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('res/file.pdf', 'content');

        $file = File::factory()->create([
            'disk' => 'local',
            'path' => 'res/file.pdf',
        ]);

        $result = (new TikaService)->resolveAbsolutePath($file);

        $this->assertFalse($result['temp']);
        $this->assertStringContainsString('file.pdf', $result['path']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fakePdfOnDisk(): string
    {
        Storage::fake('local');
        Storage::disk('local')->put('test.pdf', '%PDF-1.4 fake content');

        return Storage::disk('local')->path('test.pdf');
    }
}
