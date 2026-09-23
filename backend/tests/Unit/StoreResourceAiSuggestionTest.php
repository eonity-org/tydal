<?php

namespace Tests\Unit;

use App\Services\FileStorageService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The resource-scoped AITY archive path must be unique per write so concurrent AITY
 * processes against the same resource+purpose cannot clobber each other on disk. The
 * uniqueness comes from the caller-supplied signature (the SystemFile UUID), not from
 * the millisecond timestamp alone.
 */
class StoreResourceAiSuggestionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_signature_is_embedded_in_the_archive_path(): void
    {
        $resourceId = (string) Str::orderedUuid();
        $signature = (string) Str::orderedUuid();

        $stored = FileStorageService::storeResourceAiSuggestion($resourceId, 'tags', ['a', 'b'], $signature);

        $this->assertStringContainsString('/archives/generated_tags_', $stored['path']);
        $this->assertStringEndsWith("_{$signature}.json", $stored['path']);
        Storage::disk('local')->assertExists($stored['path']);
    }

    public function test_distinct_signatures_yield_distinct_paths_within_the_same_millisecond(): void
    {
        $resourceId = (string) Str::orderedUuid();

        // Freeze time so both writes share the exact same millisecond timestamp — only the
        // signature differentiates them. Without it the second write would overwrite the first.
        $this->travelTo(now());

        $first = FileStorageService::storeResourceAiSuggestion($resourceId, 'name', 'First', (string) Str::orderedUuid());
        $second = FileStorageService::storeResourceAiSuggestion($resourceId, 'name', 'Second', (string) Str::orderedUuid());

        $this->assertNotSame($first['path'], $second['path']);
        Storage::disk('local')->assertExists($first['path']);
        Storage::disk('local')->assertExists($second['path']);
        $this->assertSame('"First"', Storage::disk('local')->get($first['path']));
        $this->assertSame('"Second"', Storage::disk('local')->get($second['path']));
    }
}
