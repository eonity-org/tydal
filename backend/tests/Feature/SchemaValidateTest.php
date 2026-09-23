<?php

namespace Tests\Feature;

use App\Models\CollectionScheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Epic 1.2 — schema:validate enforces the field contract (docs/SCHEMA_FIELDS.md).
 */
class SchemaValidateTest extends TestCase
{
    use RefreshDatabase;

    private function makeScheme(array $fields, array $mimetypes = []): CollectionScheme
    {
        return CollectionScheme::create([
            'name' => 'scheme-under-test',
            'display_name' => 'Scheme Under Test',
            'accepted_mimetypes' => $mimetypes,
            'is_system' => false,
            'fields' => $fields,
        ]);
    }

    public function test_valid_scheme_passes(): void
    {
        $this->makeScheme([
            ['name' => 'language', 'type' => 'select', 'storage' => 'metadata',
                'es_type' => 'keyword', 'is_facet' => true,
                'validators' => ['in' => ['es', 'en']]],
            ['name' => 'summary', 'type' => 'text', 'storage' => 'metadata', 'es_type' => 'text'],
        ], ['image/jpeg', 'application/pdf', 'image/*']);

        $this->artisan('schema:validate')
            ->expectsOutputToContain('All schemes valid.')
            ->assertExitCode(0);
    }

    public function test_select_without_options_fails(): void
    {
        $this->makeScheme([
            ['name' => 'language', 'type' => 'select', 'storage' => 'metadata', 'es_type' => 'keyword'],
        ]);

        $this->artisan('schema:validate')
            ->expectsOutputToContain('requires validators.in')
            ->assertExitCode(1);
    }

    public function test_duplicate_and_malformed_names_fail(): void
    {
        $this->makeScheme([
            ['name' => 'dup', 'type' => 'string', 'es_type' => 'text'],
            ['name' => 'dup', 'type' => 'string', 'es_type' => 'text'],
            ['name' => 'BadName', 'type' => 'string', 'es_type' => 'text'],
        ]);

        $this->artisan('schema:validate')
            ->expectsOutputToContain('duplicate field name')
            ->expectsOutputToContain('snake_case')
            ->assertExitCode(1);
    }

    public function test_non_aggregatable_facet_fails(): void
    {
        $this->makeScheme([
            ['name' => 'title', 'type' => 'string', 'es_type' => 'text', 'is_facet' => true],
        ]);

        $this->artisan('schema:validate')
            ->expectsOutputToContain('aggregatable')
            ->assertExitCode(1);
    }

    public function test_text_facet_with_keyword_subfield_passes(): void
    {
        $this->makeScheme([
            ['name' => 'title', 'type' => 'string', 'es_type' => 'text', 'is_facet' => true,
                'es_fields' => ['keyword' => ['type' => 'keyword']]],
        ]);

        $this->artisan('schema:validate')->assertExitCode(0);
    }

    public function test_type_es_type_mismatch_and_bad_mimetype_fail(): void
    {
        $this->makeScheme([
            ['name' => 'count', 'type' => 'integer', 'storage' => 'metadata', 'es_type' => 'text'],
        ], ['not-a-mimetype']);

        $this->artisan('schema:validate')
            ->expectsOutputToContain('incompatible with type')
            ->expectsOutputToContain('not a valid type/subtype')
            ->assertExitCode(1);
    }
}
