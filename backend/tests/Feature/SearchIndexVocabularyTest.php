<?php

namespace Tests\Feature;

use App\Exceptions\IndexVocabularyConflict;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\SearchIndex;
use App\Models\User;
use App\Services\ElasticsearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two schemes on one index — the mapping is their union, and an irreconcilable
 * declaration stops the run.
 *
 * Before this, `setupIndex` derived the mapping from the first collection
 * linked to the index; the other schemes' fields fell through to `dynamic:
 * true` and were typed by inference, so a declared `keyword` became `text` and
 * its facet silently stopped aggregating. Which scheme won depended on row
 * order, which is why the assertions below deliberately query the *second*
 * collection's fields.
 */
class SearchIndexVocabularyTest extends TestCase
{
    use RefreshDatabase;

    private SearchIndex $index;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = SearchIndex::create([
            'index_name' => 'shared_test_index',
            'display_name' => 'Shared Test Index',
            'is_active' => true,
        ]);

        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create();
    }

    private function collectionWithScheme(string $schemeName, array $fields): Collection
    {
        $scheme = CollectionScheme::create([
            'name' => $schemeName,
            'display_name' => $schemeName,
            'accepted_mimetypes' => ['image/*'],
            'is_system' => false,
            'fields' => $fields,
        ]);

        return Collection::create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->owner->id,
            'name' => $schemeName,
            'slug' => str_replace('_', '-', $schemeName),
            'scheme_id' => $scheme->id,
            'index_id' => $this->index->id,
            'is_active' => true,
        ]);
    }

    private function field(string $name, array $overrides = []): array
    {
        return array_merge([
            'name' => $name,
            'type' => 'string',
            'storage' => 'metadata',
            'es_type' => 'keyword',
        ], $overrides);
    }

    public function test_the_mapping_is_the_union_of_every_scheme_on_the_index(): void
    {
        $this->collectionWithScheme('first_scheme', [$this->field('kind')]);
        $this->collectionWithScheme('second_scheme', [$this->field('format')]);

        $mappings = app(ElasticsearchService::class)->buildMappings(
            app(ElasticsearchService::class)->vocabularyFor($this->index)
        );

        $metadata = $mappings['properties']['metadata']['properties'];

        $this->assertArrayHasKey('kind', $metadata);
        // The one that used to be dropped: declared by the *second* collection.
        $this->assertArrayHasKey('format', $metadata);
        $this->assertSame('keyword', $metadata['format']['type']);
    }

    public function test_setup_index_refuses_an_irreconcilable_vocabulary(): void
    {
        $this->collectionWithScheme('int_scheme', [
            $this->field('year', ['type' => 'integer', 'es_type' => 'integer']),
        ]);
        $this->collectionWithScheme('keyword_scheme', [$this->field('year')]);

        // Thrown before any ES call — a mapping that cannot represent the data
        // should never reach the cluster.
        $this->expectException(IndexVocabularyConflict::class);

        app(ElasticsearchService::class)->setupIndex($this->index);
    }

    public function test_setup_indices_reports_the_conflict_and_exits_nonzero(): void
    {
        $this->collectionWithScheme('int_scheme', [
            $this->field('year', ['type' => 'integer', 'es_type' => 'integer']),
        ]);
        $this->collectionWithScheme('keyword_scheme', [$this->field('year')]);

        $this->artisan('search:setup-indices', ['--index' => 'shared_test_index'])
            ->expectsOutputToContain('field vocabulary conflict')
            ->assertExitCode(1);
    }

    // -------------------------------------------------------------------------
    // search:indexes
    // -------------------------------------------------------------------------

    public function test_it_lists_the_schemes_sharing_an_index(): void
    {
        $this->collectionWithScheme('first_scheme', [$this->field('license')]);
        $this->collectionWithScheme('second_scheme', [$this->field('license')]);

        // Substrings are matched by Mockery against each write, first
        // expectation wins — so these must not overlap. The header is the only
        // line carrying neither scheme name.
        $this->artisan('search:indexes', ['--index' => 'shared_test_index'])
            ->expectsOutputToContain('vocabulary (1 term(s))')
            ->expectsOutputToContain('first_scheme')
            ->expectsOutputToContain('second_scheme')
            ->assertExitCode(0);
    }

    public function test_check_exits_nonzero_on_a_conflict(): void
    {
        $this->collectionWithScheme('int_scheme', [
            $this->field('year', ['type' => 'integer', 'es_type' => 'integer']),
        ]);
        $this->collectionWithScheme('keyword_scheme', [$this->field('year')]);

        $this->artisan('search:indexes', ['--index' => 'shared_test_index', '--check' => true])
            ->expectsOutputToContain('vocabulary conflict')
            ->assertExitCode(1);
    }

    public function test_a_divergence_warns_but_passes_unless_strict(): void
    {
        $this->collectionWithScheme('facet_scheme', [$this->field('license', ['is_facet' => true])]);
        $this->collectionWithScheme('plain_scheme', [$this->field('license', ['is_facet' => false])]);

        $this->artisan('search:indexes', ['--index' => 'shared_test_index', '--check' => true])
            ->expectsOutputToContain('divergence')
            ->assertExitCode(0);

        $this->artisan('search:indexes', [
            '--index' => 'shared_test_index', '--check' => true, '--strict' => true,
        ])->assertExitCode(1);
    }

    public function test_a_coherent_index_reports_clean(): void
    {
        $this->collectionWithScheme('first_scheme', [$this->field('license')]);
        $this->collectionWithScheme('second_scheme', [$this->field('license')]);

        $this->artisan('search:indexes', ['--index' => 'shared_test_index', '--check' => true])
            ->expectsOutputToContain('All index vocabularies coherent.')
            ->assertExitCode(0);
    }
}
