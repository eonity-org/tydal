<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Enums\SystemFilePurpose;
use App\Jobs\AutoTagResource;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Models\User;
use App\Services\AutoApprovalService;
use App\Services\LLM\Contracts\LlmServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Epic 3.3 seed — AI suggestions for user-defined scheme fields: the ai_fill
 * contract drives extraction, the scheme's own validation gates application.
 */
class AiMetadataSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private Resource $resource;

    private Organization $org;

    private string $sourceFileId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $user = User::factory()->create();
        $this->org = Organization::factory()->create();

        $scheme = CollectionScheme::create([
            'name' => 'artworks',
            'display_name' => 'Artworks',
            'accepted_mimetypes' => [],
            'is_system' => false,
            'fields' => [
                ['name' => 'technique', 'type' => 'select', 'es_type' => 'keyword', 'storage' => 'metadata',
                    'validators' => ['in' => ['oil', 'acrylic']],
                    'ai_fill' => ['enabled' => true, 'hint' => 'the painting technique']],
                ['name' => 'year', 'type' => 'integer', 'es_type' => 'integer', 'storage' => 'metadata',
                    'ai_fill' => ['enabled' => true, 'hint' => 'the creation year']],
                ['name' => 'internal_ref', 'type' => 'string', 'es_type' => 'keyword', 'storage' => 'metadata'],
            ],
        ]);

        $collection = Collection::factory()->create([
            'organization_id' => $this->org->id,
            'user_owner_id' => $user->id,
            'scheme_id' => $scheme->id,
        ]);

        $this->resource = Resource::factory()->create([
            'collection_id' => $collection->id,
            'organization_id' => $this->org->id,
            'user_owner_id' => $user->id,
            'state' => ResourceState::LIVE->value,
        ]);

        $file = File::factory()->forResource($this->resource->id)->create([
            'disk' => 'local',
            'path' => 'uploads/artwork.pdf',
            'mime_type' => 'application/pdf',
        ]);
        $this->sourceFileId = $file->id;

        $chunks = [['sequence' => 0, 'page_number' => 1, 'content' => 'Óleo sobre lienzo pintado en 1898.', 'word_count' => 6, 'char_start' => 0, 'char_end' => 34]];
        $path = "resources/{$this->resource->id}/archives/{$file->id}.json.gz";
        Storage::disk('local')->put($path, gzencode(json_encode($chunks)));

        SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $file->id,
            'purpose' => SystemFilePurpose::EXTRACTED_TEXT,
            'filename' => "{$file->id}.json.gz",
            'mime_type' => 'application/gzip',
            'size' => 100,
            'path' => $path,
            'disk' => 'local',
            'is_active' => true,
            'metadata' => ['chunk_count' => 1],
        ]);

        Config::set('autotagging.enabled', true);
        Config::set('autotagging.max_chars', 6000);
        Config::set('autotagging.max_tags', 10);
    }

    private function llmMock(): MockInterface
    {
        $mock = Mockery::mock(LlmServiceInterface::class);
        $mock->shouldReceive('getModel')->zeroOrMoreTimes()->andReturn('test-model');

        return $mock;
    }

    // =========================================================================
    // Generation (AutoTagResource)
    // =========================================================================

    public function test_prompt_carries_ai_fill_fields_with_closed_option_lists(): void
    {
        $llm = $this->llmMock();
        $llm->shouldReceive('chat')
            ->once()
            ->withArgs(function (array $messages) {
                $user = $messages[1]['content'];

                return str_contains($user, 'suggested_metadata')
                    && str_contains($user, '"technique": exactly one of: oil | acrylic — the painting technique')
                    && str_contains($user, '"year": an integer — the creation year')
                    && ! str_contains($user, 'internal_ref'); // not ai_fill-enabled
            })
            ->andReturn(json_encode([
                'suggested_name' => 'Óleo de 1898',
                'suggested_description' => 'Pintura al óleo.',
                'suggested_tags' => [],
                'suggested_metadata' => ['technique' => 'oil', 'year' => 1898, 'internal_ref' => 'LEAK-1'],
            ]));
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);

        $row = SystemFile::where('purpose', SystemFilePurpose::AI_SUGGESTED_METADATA->value)
            ->where('source_file_id', $this->sourceFileId)
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals(['technique' => 'oil', 'year' => 1898], $row->metadata['value'],
            'only ai_fill-enabled fields are kept — internal_ref dropped');
    }

    // =========================================================================
    // Application (AutoApprovalService) — validated, fill-empty-only
    // =========================================================================

    private function storeMetadataSuggestion(array $value): void
    {
        SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $this->sourceFileId,
            'purpose' => SystemFilePurpose::AI_SUGGESTED_METADATA,
            'filename' => 'meta.json',
            'mime_type' => 'application/json',
            'size' => 10,
            'path' => 'system/meta.json',
            'disk' => 'local',
            'is_active' => true,
            'metadata' => ['value' => $value],
        ]);
    }

    private function approve(): void
    {
        app(AutoApprovalService::class)->approve(
            Resource::whereKey($this->resource->id)->get(),
            $this->org->id,
            ['apply_tags' => false, 'dedup' => false],
        );
    }

    public function test_valid_suggestions_are_applied_through_scheme_validation(): void
    {
        $this->storeMetadataSuggestion(['technique' => 'oil', 'year' => 1898]);

        $this->approve();

        $metadata = $this->resource->fresh()->metadata;
        $this->assertSame('oil', $metadata['technique']);
        $this->assertSame(1898, $metadata['year']);
    }

    public function test_invalid_values_are_dropped_without_blocking_valid_ones(): void
    {
        // 'gouache' is outside the select's closed list — scheme validation rejects it
        $this->storeMetadataSuggestion(['technique' => 'gouache', 'year' => 1898]);

        $this->approve();

        $metadata = $this->resource->fresh()->metadata;
        $this->assertArrayNotHasKey('technique', $metadata ?? []);
        $this->assertSame(1898, $metadata['year']);
    }

    public function test_existing_curator_values_are_never_clobbered(): void
    {
        $this->resource->update(['metadata' => ['technique' => 'acrylic']]);
        $this->storeMetadataSuggestion(['technique' => 'oil', 'year' => 1898]);

        $this->approve();

        $metadata = $this->resource->fresh()->metadata;
        $this->assertSame('acrylic', $metadata['technique'], 'curator input wins');
        $this->assertSame(1898, $metadata['year'], 'empty slot still filled');
    }

    public function test_metadata_suggestion_marked_applied_after_approve(): void
    {
        $this->storeMetadataSuggestion(['year' => 1898]);

        $this->approve();

        $row = SystemFile::where('purpose', SystemFilePurpose::AI_SUGGESTED_METADATA->value)->first();
        $this->assertNotNull($row->applied_at);
    }

    // =========================================================================
    // Status machine — metadata suggestions drive aity_status like the others
    // =========================================================================

    public function test_pending_metadata_only_suggestion_surfaces_suggestions_made(): void
    {
        $this->storeMetadataSuggestion(['year' => 1898]);

        $this->resource->recomputeAndSaveAityStatus();

        $this->assertSame('suggestions_made', $this->resource->fresh()->aity_status);
    }

    public function test_auto_approved_metadata_promotes_to_automatic_review_done(): void
    {
        $this->storeMetadataSuggestion(['year' => 1898]);

        $this->approve(); // stamps applied_by_aity and recomputes

        $this->assertSame('automatic_review_done', $this->resource->fresh()->aity_status);
    }
}
