<?php

namespace App\Services\Processing;

class ChunkingService
{
    /**
     * MIME types with hard structural boundaries (page / slide / sheet).
     * Each segment is chunked independently — boundaries are never crossed.
     */
    private const STRUCTURAL_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.ms-powerpoint',
        'application/vnd.oasis.opendocument.presentation',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/vnd.oasis.opendocument.spreadsheet',
    ];

    /**
     * Split Tika XHTML output into overlapping chunks.
     *
     * Two strategies:
     *   - Structural (PDF, PPTX, XLSX): each page / slide / sheet is chunked
     *     independently. Boundaries are hard stops — words never merge across pages.
     *     Guarantees at least one chunk per non-empty segment.
     *   - Flat (DOCX, TXT, MD, HTML, …): all paragraphs are merged into one word
     *     stream and a sliding window is applied across them.
     *
     * char_start / char_end are byte offsets within the full concatenated plain
     * text (all chunks' content if joined with spaces). They serve as stable
     * anchors for Phase 2 citation retrieval.
     *
     * @param  string  $xhtml  XHTML from TikaService::extractText()
     * @param  string  $mimeType  Original file MIME type
     * @param  int  $chunkSize  Target words per chunk
     * @param  int  $overlap  Words shared between consecutive chunks
     * @return array<int, array{
     *   sequence: int,
     *   page_number: int|null,
     *   content: string,
     *   word_count: int,
     *   char_start: int,
     *   char_end: int,
     * }>
     */
    public function chunk(string $xhtml, string $mimeType, int $chunkSize = 400, int $overlap = 50): array
    {
        $segments = $this->extractSegments($xhtml, $mimeType);

        if (in_array($mimeType, self::STRUCTURAL_MIMES, true)) {
            return $this->buildChunksPerSegment($segments, $chunkSize, $overlap);
        }

        // Flat strategy: merge all paragraphs into one word stream
        $wordStream = [];
        foreach ($segments as $segment) {
            $words = preg_split('/\s+/', trim($segment['text']), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($words as $word) {
                $wordStream[] = ['word' => $word, 'page' => $segment['page']];
            }
        }

        return $this->buildWordWindowChunks($wordStream, $chunkSize, $overlap);
    }

    // =========================================================================
    // SEGMENT EXTRACTION
    // =========================================================================

    /**
     * Pull text segments from Tika XHTML, preserving page/slide numbers.
     *
     * @return array<int, array{text: string, page: int|null}>
     */
    private function extractSegments(string $xhtml, string $mimeType): array
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$xhtml, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        if ($mimeType === 'application/pdf') {
            return $this->extractByDivClass($xpath, 'page');
        }

        if (in_array($mimeType, [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint',
            'application/vnd.oasis.opendocument.presentation',
        ], true)) {
            return $this->extractByDivClass($xpath, 'slide');
        }

        if (in_array($mimeType, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'application/vnd.oasis.opendocument.spreadsheet',
        ], true)) {
            return $this->extractByDivClass($xpath, 'sheet');
        }

        return $this->extractByParagraphs($xpath);
    }

    /**
     * Extract segments by div class (page, slide, sheet).
     * Falls back to paragraph extraction if Tika emitted no matching divs.
     */
    private function extractByDivClass(\DOMXPath $xpath, string $className): array
    {
        $nodes = $xpath->query(
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' {$className} ')]"
        );
        $segments = [];
        $pageNum = 1;

        foreach ($nodes as $node) {
            $text = $this->nodeText($node);
            if (trim($text) !== '') {
                $segments[] = ['text' => $text, 'page' => $pageNum];
            }
            $pageNum++;
        }

        return ! empty($segments) ? $segments : $this->extractByParagraphs($xpath);
    }

    /**
     * Extract segments from paragraph-level block elements.
     * Falls back to double-newline splitting if no <p> elements found.
     */
    private function extractByParagraphs(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//p | //h1 | //h2 | //h3 | //h4 | //h5 | //h6');
        $segments = [];

        foreach ($nodes as $node) {
            $text = trim($this->nodeText($node));
            if ($text !== '') {
                $segments[] = ['text' => $text, 'page' => null];
            }
        }

        if (! empty($segments)) {
            return $segments;
        }

        // Last resort: split body text on blank lines
        $body = $xpath->query('//body');
        $raw = $body->length > 0 ? $this->nodeText($body->item(0)) : '';
        foreach (preg_split('/\n{2,}/', $raw) as $para) {
            $para = trim($para);
            if ($para !== '') {
                $segments[] = ['text' => $para, 'page' => null];
            }
        }

        return $segments;
    }

    // =========================================================================
    // CHUNK BUILDING
    // =========================================================================

    /**
     * Structural strategy: process each segment (page/slide/sheet) independently.
     *
     * Each segment produces at least one chunk. The sliding window never crosses
     * a segment boundary, so page_number is always exact.
     *
     * char_start / char_end are adjusted so they form a continuous offset space
     * across all segments (segment N's offsets follow on from segment N-1's last
     * char_end + 1 separator).
     */
    private function buildChunksPerSegment(array $segments, int $chunkSize, int $overlap): array
    {
        $allChunks = [];
        $globalOffset = 0;

        foreach ($segments as $segment) {
            $words = preg_split('/\s+/', trim($segment['text']), -1, PREG_SPLIT_NO_EMPTY);
            if (empty($words)) {
                continue;
            }

            $wordStream = array_map(fn ($w) => ['word' => $w, 'page' => $segment['page']], $words);
            $segChunks = $this->buildWordWindowChunks($wordStream, $chunkSize, $overlap);

            foreach ($segChunks as $chunk) {
                $chunk['sequence'] = count($allChunks);
                $chunk['char_start'] += $globalOffset;
                $chunk['char_end'] += $globalOffset;
                $allChunks[] = $chunk;
            }

            if (! empty($allChunks)) {
                $globalOffset = $allChunks[count($allChunks) - 1]['char_end'] + 1;
            }
        }

        return $allChunks;
    }

    /**
     * Flat strategy: apply a sliding word window across the full word stream.
     *
     * Overlap = words shared between consecutive chunks for context continuity.
     * Advance = chunkSize - overlap words per step.
     *
     * char_start / char_end are offsets in the stream's plain text
     * (all words joined with single spaces).
     *
     * @param  array<int, array{word: string, page: int|null}>  $wordStream
     * @return array<int, array{sequence: int, page_number: int|null, content: string, word_count: int, char_start: int, char_end: int}>
     */
    private function buildWordWindowChunks(array $wordStream, int $chunkSize, int $overlap): array
    {
        $total = count($wordStream);
        $chunks = [];

        if ($total === 0) {
            return [];
        }

        $advance = max(1, $chunkSize - $overlap);
        $sequence = 0;
        $charOffset = 0;
        $i = 0;

        while ($i < $total) {
            $window = array_slice($wordStream, $i, $chunkSize);
            $words = array_column($window, 'word');
            $content = implode(' ', $words);
            $wordCount = count($words);
            $charStart = $charOffset;
            $charEnd = $charOffset + strlen($content);
            $pageNum = $window[0]['page'] ?? null;

            $chunks[] = [
                'sequence' => $sequence++,
                'page_number' => $pageNum,
                'content' => $content,
                'word_count' => $wordCount,
                'char_start' => $charStart,
                'char_end' => $charEnd,
            ];

            // Advance charOffset by the non-overlapping portion of this chunk
            $nonOverlap = array_slice($words, 0, $advance);
            $charOffset += strlen(implode(' ', $nonOverlap)) + 1; // +1 for the trailing space

            $i += $advance;
        }

        return $chunks;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function nodeText(\DOMNode $node): string
    {
        return preg_replace('/\s+/', ' ', $node->textContent);
    }
}
