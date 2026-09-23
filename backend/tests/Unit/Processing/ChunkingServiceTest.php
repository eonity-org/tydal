<?php

namespace Tests\Unit\Processing;

use App\Services\Processing\ChunkingService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ChunkingService.
 * Pure unit tests — no database, no HTTP, no disk I/O.
 */
class ChunkingServiceTest extends TestCase
{
    private ChunkingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChunkingService;
    }

    // =========================================================================
    // Boundary strategies
    // =========================================================================

    public function test_pdf_is_chunked_by_page_div(): void
    {
        $xhtml = $this->xhtml('
            <div class="page"><p>First page content with words.</p></div>
            <div class="page"><p>Second page content with words.</p></div>
        ');

        $chunks = $this->service->chunk($xhtml, 'application/pdf');

        $this->assertCount(2, $chunks);
        $this->assertSame(1, $chunks[0]['page_number']);
        $this->assertSame(2, $chunks[1]['page_number']);
    }

    public function test_pptx_is_chunked_by_slide_div(): void
    {
        $xhtml = $this->xhtml('
            <div class="slide"><p>Slide one content.</p></div>
            <div class="slide"><p>Slide two content.</p></div>
            <div class="slide"><p>Slide three content.</p></div>
        ');

        $chunks = $this->service->chunk(
            $xhtml,
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'
        );

        $this->assertCount(3, $chunks);
        $this->assertSame(1, $chunks[0]['page_number']);
        $this->assertSame(3, $chunks[2]['page_number']);
    }

    public function test_docx_is_chunked_by_paragraph(): void
    {
        $xhtml = $this->xhtml('
            <p>First paragraph content.</p>
            <p>Second paragraph content.</p>
            <p>Third paragraph content.</p>
        ');

        $chunks = $this->service->chunk(
            $xhtml,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );

        $this->assertGreaterThanOrEqual(1, count($chunks));
        $this->assertNull($chunks[0]['page_number']);
    }

    public function test_plain_text_is_chunked_by_paragraph(): void
    {
        $xhtml = $this->xhtml('<p>Alpha beta gamma.</p><p>Delta epsilon zeta.</p>');

        $chunks = $this->service->chunk($xhtml, 'text/plain');

        $this->assertNotEmpty($chunks);
        $this->assertNull($chunks[0]['page_number']);
    }

    // =========================================================================
    // Chunk size & sliding window
    // =========================================================================

    public function test_large_segment_is_split_into_multiple_chunks(): void
    {
        // 50 words in one page div, chunkSize=10 → at least 5 chunks
        $words = implode(' ', array_fill(0, 50, 'word'));
        $xhtml = $this->xhtml("<div class=\"page\"><p>{$words}</p></div>");

        $chunks = $this->service->chunk($xhtml, 'application/pdf', 10, 2);

        $this->assertGreaterThan(4, count($chunks));
        $this->assertSame(10, $chunks[0]['word_count']);
    }

    public function test_overlap_words_appear_in_consecutive_chunks(): void
    {
        // 20 distinct words, chunkSize=10, overlap=3
        $words = array_map(fn ($i) => "word{$i}", range(1, 20));
        $xhtml = $this->xhtml('<p>'.implode(' ', $words).'</p>');

        $chunks = $this->service->chunk($xhtml, 'text/plain', 10, 3);

        // Chunk 0: words 1-10, chunk 1: words 8-17 (overlap = words 8-10)
        $chunk0Words = explode(' ', $chunks[0]['content']);
        $chunk1Words = explode(' ', $chunks[1]['content']);

        $overlapIn0 = array_slice($chunk0Words, -3);   // last 3 of chunk 0
        $overlapIn1 = array_slice($chunk1Words, 0, 3);  // first 3 of chunk 1

        $this->assertSame($overlapIn0, $overlapIn1);
    }

    // =========================================================================
    // Sequence and offsets
    // =========================================================================

    public function test_sequence_is_zero_indexed_and_consecutive(): void
    {
        $words = implode(' ', array_fill(0, 30, 'x'));
        $xhtml = $this->xhtml("<p>{$words}</p>");

        $chunks = $this->service->chunk($xhtml, 'text/plain', 10, 2);

        foreach ($chunks as $i => $chunk) {
            $this->assertSame($i, $chunk['sequence']);
        }
    }

    public function test_char_start_is_zero_for_first_chunk(): void
    {
        $xhtml = $this->xhtml('<p>Hello world test content here.</p>');

        $chunks = $this->service->chunk($xhtml, 'text/plain', 100, 0);

        $this->assertSame(0, $chunks[0]['char_start']);
    }

    public function test_char_end_equals_char_start_plus_content_length(): void
    {
        $xhtml = $this->xhtml('<p>alpha beta gamma delta epsilon zeta eta theta iota kappa</p>');

        $chunks = $this->service->chunk($xhtml, 'text/plain', 5, 0);

        foreach ($chunks as $chunk) {
            $this->assertSame(
                $chunk['char_start'] + strlen($chunk['content']),
                $chunk['char_end']
            );
        }
    }

    public function test_char_start_of_second_chunk_is_beyond_first_chunk_non_overlap(): void
    {
        $words = array_map(fn ($i) => "w{$i}", range(1, 20));
        $xhtml = $this->xhtml('<p>'.implode(' ', $words).'</p>');

        $chunks = $this->service->chunk($xhtml, 'text/plain', 10, 2);

        // Second chunk starts after the non-overlapping portion of the first
        $this->assertGreaterThan($chunks[0]['char_start'], $chunks[1]['char_start']);
        $this->assertLessThan($chunks[0]['char_end'], $chunks[1]['char_start']);
    }

    public function test_word_count_matches_actual_words_in_content(): void
    {
        $xhtml = $this->xhtml('<p>one two three four five six seven eight nine ten</p>');

        $chunks = $this->service->chunk($xhtml, 'text/plain', 5, 0);

        foreach ($chunks as $chunk) {
            $actual = count(explode(' ', $chunk['content']));
            $this->assertSame($chunk['word_count'], $actual);
        }
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function test_empty_xhtml_returns_empty_array(): void
    {
        $chunks = $this->service->chunk('<html><body></body></html>', 'application/pdf');

        $this->assertSame([], $chunks);
    }

    public function test_whitespace_only_xhtml_returns_empty_array(): void
    {
        $xhtml = $this->xhtml('<div class="page">   </div>');

        $chunks = $this->service->chunk($xhtml, 'application/pdf');

        $this->assertSame([], $chunks);
    }

    public function test_single_word_produces_one_chunk(): void
    {
        $xhtml = $this->xhtml('<p>Hello</p>');

        $chunks = $this->service->chunk($xhtml, 'text/plain', 400, 50);

        $this->assertCount(1, $chunks);
        $this->assertSame('Hello', $chunks[0]['content']);
        $this->assertSame(1, $chunks[0]['word_count']);
    }

    public function test_missing_page_divs_fall_back_to_paragraphs(): void
    {
        // PDF XHTML with no <div class="page"> — fallback to <p> elements
        $xhtml = $this->xhtml('<p>Paragraph one.</p><p>Paragraph two.</p>');

        $chunks = $this->service->chunk($xhtml, 'application/pdf', 400, 50);

        $this->assertNotEmpty($chunks);
        $this->assertStringContainsString('Paragraph', $chunks[0]['content']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function xhtml(string $body): string
    {
        return '<html><body>'.$body.'</body></html>';
    }
}
