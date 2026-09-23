<?php

namespace Database\Seeders;

use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\SearchIndex;
use App\Services\ElasticsearchService;
use Illuminate\Database\Seeder;

/**
 * Collection Schema Seeder
 *
 * Seeds the default search index and collection scheme used by the system.
 * Replaces the old CollectionSchemaTemplate + SolrCoreSchema seeds.
 */
class CollectionSchemaSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSearchIndexes();
        $this->seedCollectionSchemes();
        $this->wireCollections();
    }

    protected function seedSearchIndexes(): void
    {
        SearchIndex::updateOrCreate(
            ['index_name' => 'tydal_multimedia'],
            [
                'display_name' => 'Multimedia Index',
                'description' => 'Elasticsearch index for multimedia resources (images, videos, audio)',
                'index_mappings' => null,
                'is_active' => true,
            ]
        );

        SearchIndex::updateOrCreate(
            ['index_name' => 'tydal_documents'],
            [
                'display_name' => 'Documents Index',
                'description' => 'Elasticsearch index for document resources (Word, PDF, text)',
                'index_mappings' => null,
                'is_active' => true,
            ]
        );

        SearchIndex::updateOrCreate(
            ['index_name' => 'tydal_general'],
            [
                'display_name' => 'General Index',
                'description' => 'Elasticsearch index for the general collection (no mimetype restriction)',
                'index_mappings' => null,
                'is_active' => true,
            ]
        );

        $this->command->info('✓ Search indexes seeded successfully');
    }

    protected function seedCollectionSchemes(): void
    {
        CollectionScheme::updateOrCreate(
            ['name' => 'multimedia'],
            [
                'display_name' => 'Multimedia Collection',
                'description' => 'Scheme for multimedia collections (images, videos, audio)',
                'accepted_mimetypes' => ['image/*', 'video/*', 'audio/*'],
                'is_system' => true,
                'fields' => [
                    [
                        'name' => 'name',
                        'display_name' => 'Name',
                        'type' => 'string',
                        'required' => true,
                        'storage' => 'column',
                        'is_facet' => false,
                        'display_in_form' => true,
                        'order' => 1,
                        'validators' => ['min_length' => 3, 'max_length' => 255],
                        'es_type' => 'text',
                        'es_fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]],
                    ],
                    [
                        'name' => 'description',
                        'display_name' => 'Description',
                        'type' => 'text',
                        'required' => true,
                        'storage' => 'column',
                        'is_facet' => false,
                        'display_in_form' => true,
                        'order' => 2,
                        'validators' => ['min_length' => 10],
                        'es_type' => 'text',
                    ],
                    [
                        'name' => 'language',
                        'display_name' => 'Language',
                        'type' => 'select',
                        'required' => false,
                        'storage' => 'metadata',
                        'is_facet' => true,
                        'facet_label' => 'Language',
                        'facet_order' => 1,
                        'display_in_form' => true,
                        'order' => 3,
                        // select widgets render their options from validators.in
                        'validators' => ['in' => ['es', 'en', 'fr', 'de', 'it', 'pt', 'ca', 'other']],
                        'es_type' => 'keyword',
                    ],
                    [
                        'name' => 'type',
                        'display_name' => 'Resource Type',
                        'type' => 'string',
                        'required' => false,
                        'storage' => 'column',
                        'is_facet' => true,
                        'facet_label' => 'Type',
                        'facet_order' => 2,
                        'display_in_form' => false,
                        'order' => 99,
                        'validators' => null,
                        'es_type' => 'keyword',
                    ],
                ],
            ]
        );

        CollectionScheme::updateOrCreate(
            ['name' => 'documents'],
            [
                'display_name' => 'Documents Collection',
                'description' => 'Scheme for document collections (Word, PDF, text)',
                'accepted_mimetypes' => [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'text/plain',
                    'image/*',
                ],
                'is_system' => true,
                'fields' => [
                    [
                        'name' => 'name',
                        'display_name' => 'Name',
                        'type' => 'string',
                        'required' => true,
                        'storage' => 'column',
                        'is_facet' => false,
                        'display_in_form' => true,
                        'order' => 1,
                        'validators' => ['min_length' => 3, 'max_length' => 255],
                        'es_type' => 'text',
                        'es_fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]],
                    ],
                    [
                        'name' => 'description',
                        'display_name' => 'Description',
                        'type' => 'text',
                        'required' => true,
                        'storage' => 'column',
                        'is_facet' => false,
                        'display_in_form' => true,
                        'order' => 2,
                        'validators' => ['min_length' => 10],
                        'es_type' => 'text',
                    ],
                    [
                        'name' => 'language',
                        'display_name' => 'Language',
                        'type' => 'select',
                        'required' => false,
                        'storage' => 'metadata',
                        'is_facet' => true,
                        'facet_label' => 'Language',
                        'facet_order' => 1,
                        'display_in_form' => true,
                        'order' => 3,
                        'validators' => ['in' => ['es', 'en', 'fr', 'de', 'it', 'pt', 'ca', 'other']],
                        'es_type' => 'keyword',
                    ],
                    [
                        'name' => 'author',
                        'display_name' => 'Author',
                        'type' => 'string',
                        'required' => false,
                        'storage' => 'metadata',
                        'is_facet' => false,
                        'display_in_form' => true,
                        'order' => 4,
                        'validators' => ['max_length' => 255],
                        'es_type' => 'text',
                        'es_fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]],
                    ],
                    [
                        'name' => 'type',
                        'display_name' => 'Resource Type',
                        'type' => 'string',
                        'required' => false,
                        'storage' => 'column',
                        'is_facet' => true,
                        'facet_label' => 'Type',
                        'facet_order' => 2,
                        'display_in_form' => false,
                        'order' => 99,
                        'validators' => null,
                        'es_type' => 'keyword',
                    ],
                ],
            ]
        );

        CollectionScheme::updateOrCreate(
            ['name' => 'general'],
            [
                'display_name' => 'General Collection',
                'description' => 'Everything-fits scheme with no mimetype restriction — accepts multimedia and document types alike',
                // null, not a union list: ResourceController::uploadFile skips
                // MIME gating entirely when accepted_mimetypes is empty/null
                // (`! empty($acceptedMimetypes)`), so this is genuinely
                // unrestricted rather than a maintained list that drifts from
                // 'multimedia'/'documents'.
                'accepted_mimetypes' => null,
                'is_system' => true,
                // Same field set as 'documents' (a superset of 'multimedia's):
                // name/description/language/type plus 'author', which is just
                // as meaningful on non-document resources here.
                'fields' => [
                    [
                        'name' => 'name',
                        'display_name' => 'Name',
                        'type' => 'string',
                        'required' => true,
                        'storage' => 'column',
                        'is_facet' => false,
                        'display_in_form' => true,
                        'order' => 1,
                        'validators' => ['min_length' => 3, 'max_length' => 255],
                        'es_type' => 'text',
                        'es_fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]],
                    ],
                    [
                        'name' => 'description',
                        'display_name' => 'Description',
                        'type' => 'text',
                        'required' => true,
                        'storage' => 'column',
                        'is_facet' => false,
                        'display_in_form' => true,
                        'order' => 2,
                        'validators' => ['min_length' => 10],
                        'es_type' => 'text',
                    ],
                    [
                        'name' => 'language',
                        'display_name' => 'Language',
                        'type' => 'select',
                        'required' => false,
                        'storage' => 'metadata',
                        'is_facet' => true,
                        'facet_label' => 'Language',
                        'facet_order' => 1,
                        'display_in_form' => true,
                        'order' => 3,
                        'validators' => ['in' => ['es', 'en', 'fr', 'de', 'it', 'pt', 'ca', 'other']],
                        'es_type' => 'keyword',
                    ],
                    [
                        'name' => 'author',
                        'display_name' => 'Author',
                        'type' => 'string',
                        'required' => false,
                        'storage' => 'metadata',
                        'is_facet' => false,
                        'display_in_form' => true,
                        'order' => 4,
                        'validators' => ['max_length' => 255],
                        'es_type' => 'text',
                        'es_fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]],
                    ],
                    [
                        'name' => 'type',
                        'display_name' => 'Resource Type',
                        'type' => 'string',
                        'required' => false,
                        'storage' => 'column',
                        'is_facet' => true,
                        'facet_label' => 'Type',
                        'facet_order' => 2,
                        'display_in_form' => false,
                        'order' => 99,
                        'validators' => null,
                        'es_type' => 'keyword',
                    ],
                ],
            ]
        );

        $this->command->info('✓ Collection schemes seeded successfully');
    }

    /**
     * Back-fill scheme_id / index_id on each starter collection (tydal-{scheme})
     * in case MinimalSeeder ran before this seeder, for every system scheme
     * that follows the tydal_{name}/tydal-{name} index/slug convention.
     */
    protected function wireCollections(): void
    {
        foreach (CollectionScheme::where('is_system', true)->get() as $scheme) {
            $index = SearchIndex::where('index_name', "tydal_{$scheme->name}")->first();
            $slug = "tydal-{$scheme->name}";

            Collection::where('slug', $slug)
                ->where(function ($q) use ($index) {
                    $q->whereNull('scheme_id');
                    if ($index) {
                        $q->orWhereNull('index_id');
                    }
                })
                ->each(function ($collection) use ($index, $scheme) {
                    $fill = [];
                    if ($index && ! $collection->index_id) {
                        $fill['index_id'] = $index->id;
                    }
                    if (! $collection->scheme_id) {
                        $fill['scheme_id'] = $scheme->id;
                    }
                    if ($fill) {
                        $collection->update($fill);

                        // The index didn't exist when MinimalSeeder created
                        // this collection (DatabaseSeeder's order) — this is
                        // the first chance to provision it. `recreate: true`
                        // is safe: only ever follows a fresh migrate.
                        if (isset($fill['index_id'])) {
                            app(ElasticsearchService::class)->provisionIndex($index, recreate: true);
                        }
                    }
                });
        }
    }
}
