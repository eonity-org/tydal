<?php

namespace Tests\Feature;

use App\Enums\SystemFilePurpose;
use App\Jobs\AutoTagResource;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SystemFile;
use App\Models\User;
use App\Services\LLM\Contracts\LlmServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AutoTagResourceJobTest extends TestCase
{
    use RefreshDatabase;

    private Resource $resource;

    private Organization $org;

    private SystemFile $systemFile;

    private string $sourceFileId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $user = User::factory()->create();
        $this->org = Organization::factory()->create();
        $scheme = CollectionScheme::create([
            'name' => 'test-scheme',
            'display_name' => 'Test',
            'accepted_mimetypes' => ['application/pdf'],
            'is_system' => false,
            'fields' => [],
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
        ]);

        $file = File::factory()->forResource($this->resource->id)->create([
            'disk' => 'local',
            'path' => 'uploads/sample.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->sourceFileId = $file->id;

        // Store a fake gzip archive (same format as real extraction)
        $chunks = [
            ['sequence' => 0, 'page_number' => 1, 'content' => 'Viaje familiar por Irlanda. Dublín es la capital.', 'word_count' => 8, 'char_start' => 0, 'char_end' => 48],
            ['sequence' => 1, 'page_number' => 1, 'content' => 'Las verdes colinas y los castillos históricos de Irlanda.', 'word_count' => 9, 'char_start' => 49, 'char_end' => 105],
        ];
        $path = "resources/{$this->resource->id}/archives/{$file->id}.json.gz";
        Storage::disk('local')->put($path, gzencode(json_encode($chunks)));

        $this->systemFile = SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $file->id,
            'purpose' => SystemFilePurpose::EXTRACTED_TEXT,
            'filename' => "{$file->id}.json.gz",
            'mime_type' => 'application/gzip',
            'size' => 100,
            'path' => $path,
            'disk' => 'local',
            'is_active' => true,
            'metadata' => ['chunk_count' => 2],
        ]);

        Config::set('autotagging.enabled', true);
        Config::set('autotagging.max_chars', 6000);
        Config::set('autotagging.max_tags', 10);
    }

    /** Returns an LlmServiceInterface mock with getModel pre-configured. */
    private function llmMock(): MockInterface
    {
        $mock = Mockery::mock(LlmServiceInterface::class);
        $mock->shouldReceive('getModel')->zeroOrMoreTimes()->andReturn('test-model');

        return $mock;
    }

    private function validLlmResponse(
        string $name = 'Viaje por Irlanda',
        string $description = 'Guía de viaje familiar por Irlanda.',
        array $tags = [['label' => 'Travel', 'description' => 'Travel content'], ['label' => 'Ireland', 'description' => 'About Ireland']]
    ): string {
        return json_encode([
            'suggested_name' => $name,
            'suggested_description' => $description,
            'suggested_tags' => $tags,
        ]);
    }

    // =========================================================================

    public function test_job_does_nothing_when_autotagging_disabled(): void
    {
        Config::set('autotagging.enabled', false);

        $llm = $this->llmMock();
        $llm->shouldNotReceive('chat');
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);

        $this->assertCount(0, $this->resource->semanticTags);
        $this->assertDatabaseMissing('system_files', ['purpose' => SystemFilePurpose::AI_SUGGESTED_TAGS->value]);
    }

    public function test_job_does_nothing_when_no_extracted_text_exists(): void
    {
        $this->systemFile->update(['is_active' => false]);

        $llm = $this->llmMock();
        $llm->shouldNotReceive('chat');
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);

        $this->assertDatabaseMissing('system_files', ['purpose' => SystemFilePurpose::AI_SUGGESTED_TAGS->value]);
    }

    public function test_job_creates_suggestion_system_files(): void
    {
        $llm = $this->llmMock();
        $llm->shouldReceive('chat')->once()->andReturn($this->validLlmResponse());
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);

        // Three separate SystemFile records must be created
        $this->assertDatabaseHas('system_files', [
            'resource_id' => $this->resource->id,
            'source_file_id' => $this->sourceFileId,
            'purpose' => SystemFilePurpose::AI_SUGGESTED_TAGS->value,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('system_files', [
            'resource_id' => $this->resource->id,
            'source_file_id' => $this->sourceFileId,
            'purpose' => SystemFilePurpose::AI_SUGGESTED_NAME->value,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('system_files', [
            'resource_id' => $this->resource->id,
            'source_file_id' => $this->sourceFileId,
            'purpose' => SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value,
            'is_active' => true,
        ]);
    }

    public function test_job_writes_archive_files_to_disk(): void
    {
        $llm = $this->llmMock();
        $llm->shouldReceive('chat')->once()->andReturn($this->validLlmResponse());
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);

        $rid = $this->resource->id;
        $fid = $this->sourceFileId;

        Storage::disk('local')->assertExists("{$rid}/archives/{$fid}_suggested_tags.json");
        Storage::disk('local')->assertExists("{$rid}/archives/{$fid}_suggested_name.json");
        Storage::disk('local')->assertExists("{$rid}/archives/{$fid}_suggested_description.json");
    }

    public function test_suggestions_stored_in_system_file_metadata(): void
    {
        $llm = $this->llmMock();
        $llm->shouldReceive('chat')->once()->andReturn($this->validLlmResponse(
            name: 'Viaje por Irlanda',
            tags: [['label' => 'Travel', 'description' => 'Travel']]
        ));
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);

        $tagsSf = SystemFile::where('purpose', SystemFilePurpose::AI_SUGGESTED_TAGS->value)->first();
        $this->assertNotNull($tagsSf);
        $this->assertArrayHasKey('value', $tagsSf->metadata);
        $this->assertSame('Travel', $tagsSf->metadata['value'][0]['label']);

        $nameSf = SystemFile::where('purpose', SystemFilePurpose::AI_SUGGESTED_NAME->value)->first();
        $this->assertSame('Viaje por Irlanda', $nameSf->metadata['value']);
    }

    public function test_no_semantic_tags_attached_to_resource(): void
    {
        $llm = $this->llmMock();
        $llm->shouldReceive('chat')->once()->andReturn($this->validLlmResponse());
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);

        // Tags must not be applied to the resource — approval is required
        $this->assertCount(0, $this->resource->fresh()->semanticTags);
    }

    public function test_rerun_deactivates_previous_suggestion_system_files(): void
    {
        $llm = $this->llmMock();
        $llm->shouldReceive('chat')->twice()->andReturn($this->validLlmResponse());
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);
        (new AutoTagResource($this->resource->id))->handle($llm);

        // After two runs, only 1 active SystemFile per purpose should remain
        $this->assertSame(1, SystemFile::where('purpose', SystemFilePurpose::AI_SUGGESTED_TAGS->value)->where('is_active', true)->count());
        $this->assertSame(1, SystemFile::where('purpose', SystemFilePurpose::AI_SUGGESTED_NAME->value)->where('is_active', true)->count());
        $this->assertSame(1, SystemFile::where('purpose', SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value)->where('is_active', true)->count());

        // One deactivated record per purpose from the first run
        $this->assertSame(1, SystemFile::where('purpose', SystemFilePurpose::AI_SUGGESTED_TAGS->value)->where('is_active', false)->count());
    }

    public function test_job_calls_llm_with_document_content(): void
    {
        $llm = $this->llmMock();
        $llm->shouldReceive('chat')
            ->once()
            ->withArgs(function (array $messages) {
                $userContent = $messages[1]['content'] ?? '';

                return str_contains($userContent, 'Irlanda')
                    && str_contains($userContent, 'suggested_name')
                    && str_contains($userContent, 'suggested_tags');
            })
            ->andReturn($this->validLlmResponse());
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);
    }

    public function test_parses_response_with_markdown_fences(): void
    {
        $llm = $this->llmMock();
        $llm->shouldReceive('chat')->once()->andReturn(
            "```json\n".$this->validLlmResponse(tags: [['label' => 'History', 'description' => 'Historical']])."\n```"
        );
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);

        $sf = SystemFile::where('purpose', SystemFilePurpose::AI_SUGGESTED_TAGS->value)->first();
        $this->assertNotNull($sf);
        $this->assertSame('History', $sf->metadata['value'][0]['label']);
    }

    public function test_does_nothing_when_llm_returns_unparseable_response(): void
    {
        $llm = $this->llmMock();
        $llm->shouldReceive('chat')->once()->andReturn('Sorry, I cannot help with that.');
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);

        $this->assertDatabaseMissing('system_files', ['purpose' => SystemFilePurpose::AI_SUGGESTED_TAGS->value]);
    }

    public function test_skips_when_source_file_id_missing(): void
    {
        $this->systemFile->update(['source_file_id' => null]);

        $llm = $this->llmMock();
        $llm->shouldNotReceive('chat');
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);

        $this->assertDatabaseMissing('system_files', ['purpose' => SystemFilePurpose::AI_SUGGESTED_TAGS->value]);
    }

    // =========================================================================
    // PER-FILE TARGETING (sourceFileId)
    // =========================================================================

    public function test_job_uses_specific_source_file_when_id_provided(): void
    {
        // Create a second file with different extracted text
        $secondFile = File::factory()->forResource($this->resource->id)->create([
            'disk' => 'local',
            'path' => 'uploads/second.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $secondChunks = [['sequence' => 0, 'page_number' => 1, 'content' => 'London is the capital of England.', 'word_count' => 6, 'char_start' => 0, 'char_end' => 33]];
        $secondPath = "resources/{$this->resource->id}/archives/{$secondFile->id}.json.gz";
        Storage::disk('local')->put($secondPath, gzencode(json_encode($secondChunks)));

        SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $secondFile->id,
            'purpose' => SystemFilePurpose::EXTRACTED_TEXT,
            'filename' => "{$secondFile->id}.json.gz",
            'mime_type' => 'application/gzip',
            'size' => 100,
            'path' => $secondPath,
            'disk' => 'local',
            'is_active' => true,
            'metadata' => ['chunk_count' => 1],
        ]);

        $llm = $this->llmMock();
        $llm->shouldReceive('chat')
            ->once()
            ->withArgs(function (array $messages) {
                // Must contain the second file's content, not the first file's (Irlanda)
                return str_contains($messages[1]['content'], 'London')
                    && ! str_contains($messages[1]['content'], 'Irlanda');
            })
            ->andReturn($this->validLlmResponse());
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id, $secondFile->id))->handle($llm);

        // Suggestions stored under the second file
        $this->assertDatabaseHas('system_files', [
            'source_file_id' => $secondFile->id,
            'purpose' => SystemFilePurpose::AI_SUGGESTED_NAME->value,
            'is_active' => true,
        ]);
        // First file must not have gained new suggestions
        $this->assertDatabaseMissing('system_files', [
            'source_file_id' => $this->sourceFileId,
            'purpose' => SystemFilePurpose::AI_SUGGESTED_NAME->value,
        ]);
    }

    public function test_job_returns_early_when_no_extracted_text_for_specific_source_file(): void
    {
        $otherFile = File::factory()->forResource($this->resource->id)->create([
            'disk' => 'local',
            'path' => 'uploads/other.pdf',
            'mime_type' => 'application/pdf',
        ]);
        // No SystemFile created for $otherFile — it has no extracted text

        $llm = $this->llmMock();
        $llm->shouldNotReceive('chat');
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id, $otherFile->id))->handle($llm);

        $this->assertDatabaseMissing('system_files', [
            'source_file_id' => $otherFile->id,
            'purpose' => SystemFilePurpose::AI_SUGGESTED_TAGS->value,
        ]);
    }

    public function test_two_files_each_produce_independent_suggestions(): void
    {
        // Represents the two-PDF race condition fix: each dispatch targets its own file.
        $secondFile = File::factory()->forResource($this->resource->id)->create([
            'disk' => 'local',
            'path' => 'uploads/second.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $secondChunks = [['sequence' => 0, 'page_number' => 1, 'content' => 'London is the capital of England.', 'word_count' => 6, 'char_start' => 0, 'char_end' => 33]];
        $secondPath = "resources/{$this->resource->id}/archives/{$secondFile->id}.json.gz";
        Storage::disk('local')->put($secondPath, gzencode(json_encode($secondChunks)));

        SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $secondFile->id,
            'purpose' => SystemFilePurpose::EXTRACTED_TEXT,
            'filename' => "{$secondFile->id}.json.gz",
            'mime_type' => 'application/gzip',
            'size' => 100,
            'path' => $secondPath,
            'disk' => 'local',
            'is_active' => true,
            'metadata' => ['chunk_count' => 1],
        ]);

        $llm = $this->llmMock();
        $llm->shouldReceive('chat')->twice()->andReturn($this->validLlmResponse());
        $this->app->instance(LlmServiceInterface::class, $llm);

        // Simulate ExtractFileText dispatching one job per file
        (new AutoTagResource($this->resource->id, $this->sourceFileId))->handle($llm);
        (new AutoTagResource($this->resource->id, $secondFile->id))->handle($llm);

        // Both files must have their own active suggestion records
        $this->assertDatabaseHas('system_files', [
            'source_file_id' => $this->sourceFileId,
            'purpose' => SystemFilePurpose::AI_SUGGESTED_NAME->value,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('system_files', [
            'source_file_id' => $secondFile->id,
            'purpose' => SystemFilePurpose::AI_SUGGESTED_NAME->value,
            'is_active' => true,
        ]);
        // Two active name suggestions — one per file
        $this->assertSame(
            2,
            SystemFile::where('resource_id', $this->resource->id)
                ->where('purpose', SystemFilePurpose::AI_SUGGESTED_NAME->value)
                ->where('is_active', true)
                ->count()
        );
    }

    // =========================================================================
    // TRANSCRIPTION FALLBACK
    // =========================================================================

    /**
     * Helper: create a TRANSCRIPTION SystemFile with fake gzip chunk data.
     */
    private function seedTranscriptionSystemFile(): SystemFile
    {
        $file = File::factory()->forResource($this->resource->id)->create([
            'disk' => 'local',
            'path' => 'uploads/sample.mp3',
            'mime_type' => 'audio/mpeg',
        ]);

        $chunks = [
            ['sequence' => 0, 'content' => 'Bienvenidos al podcast. Hablamos sobre tecnología.', 'word_count' => 7],
            ['sequence' => 1, 'content' => 'El tema de hoy es la inteligencia artificial.',       'word_count' => 7],
        ];
        $path = "resources/{$this->resource->id}/archives/{$file->id}_transcription.json.gz";
        Storage::disk('local')->put($path, gzencode(json_encode($chunks)));

        return SystemFile::create([
            'resource_id' => $this->resource->id,
            'source_file_id' => $file->id,
            'purpose' => SystemFilePurpose::TRANSCRIPTION,
            'filename' => "{$file->id}_transcription.json.gz",
            'mime_type' => 'application/gzip',
            'size' => 100,
            'path' => $path,
            'disk' => 'local',
            'is_active' => true,
            'metadata' => ['chunk_count' => 2],
        ]);
    }

    public function test_job_falls_back_to_transcription_when_no_extracted_text(): void
    {
        // Deactivate the EXTRACTED_TEXT file from setUp
        $this->systemFile->update(['is_active' => false]);
        $this->seedTranscriptionSystemFile();

        $llm = $this->llmMock();
        $llm->shouldReceive('chat')->once()->andReturn($this->validLlmResponse());
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);

        // Suggestions should have been created from the transcription
        $this->assertDatabaseHas('system_files', [
            'resource_id' => $this->resource->id,
            'purpose' => SystemFilePurpose::AI_SUGGESTED_TAGS->value,
            'is_active' => true,
        ]);
    }

    public function test_job_does_nothing_when_neither_extracted_text_nor_transcription(): void
    {
        $this->systemFile->update(['is_active' => false]);

        $llm = $this->llmMock();
        $llm->shouldNotReceive('chat');
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);

        $this->assertDatabaseMissing('system_files', ['purpose' => SystemFilePurpose::AI_SUGGESTED_TAGS->value]);
    }

    public function test_extracted_text_takes_priority_over_transcription(): void
    {
        // Both are active — EXTRACTED_TEXT should win
        $this->seedTranscriptionSystemFile();

        $llm = $this->llmMock();
        $llm->shouldReceive('chat')
            ->once()
            ->withArgs(function (array $messages) {
                // Prompt must say "document", not "audio transcript"
                return str_contains($messages[1]['content'], 'document')
                    && ! str_contains($messages[1]['content'], 'audio transcript');
            })
            ->andReturn($this->validLlmResponse());
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);
    }

    public function test_transcription_prompt_uses_audio_transcript_label(): void
    {
        $this->systemFile->update(['is_active' => false]);
        $this->seedTranscriptionSystemFile();

        $llm = $this->llmMock();
        $llm->shouldReceive('chat')
            ->once()
            ->withArgs(function (array $messages) {
                return str_contains($messages[1]['content'], 'audio transcript')
                    && str_contains($messages[0]['content'], 'audio transcript');
            })
            ->andReturn($this->validLlmResponse());
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);
    }

    public function test_transcription_content_reaches_llm(): void
    {
        $this->systemFile->update(['is_active' => false]);
        $this->seedTranscriptionSystemFile();

        $llm = $this->llmMock();
        $llm->shouldReceive('chat')
            ->once()
            ->withArgs(function (array $messages) {
                return str_contains($messages[1]['content'], 'inteligencia artificial');
            })
            ->andReturn($this->validLlmResponse());
        $this->app->instance(LlmServiceInterface::class, $llm);

        (new AutoTagResource($this->resource->id))->handle($llm);
    }
}
