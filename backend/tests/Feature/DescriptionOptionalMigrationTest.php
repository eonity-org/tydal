<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #24: the built-in schemes stop requiring `description`; other fields and
 * other schemes are left alone.
 */
class DescriptionOptionalMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function insertScheme(string $name, bool $descriptionRequired): void
    {
        DB::table('collection_schemes')->insert([
            'id' => (string) Str::uuid7(),
            'name' => $name,
            'display_name' => ucfirst($name),
            'fields' => json_encode([
                ['name' => 'name', 'storage' => 'column', 'required' => true],
                ['name' => 'description', 'storage' => 'column', 'required' => $descriptionRequired, 'validators' => ['min_length' => 10]],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function fieldsOf(string $name): array
    {
        return json_decode((string) DB::table('collection_schemes')->where('name', $name)->value('fields'), true);
    }

    public function test_system_schemes_no_longer_require_a_description(): void
    {
        DB::table('collection_schemes')->whereIn('name', ['general', 'custom_one'])->delete();
        $this->insertScheme('general', true);
        $this->insertScheme('custom_one', true);

        $migration = require database_path('migrations/2026_10_05_000000_make_description_optional_in_system_schemes.php');
        $migration->up();

        $general = collect($this->fieldsOf('general'))->keyBy('name');
        $this->assertFalse($general['description']['required']);
        $this->assertSame(['min_length' => 10], $general['description']['validators']);
        $this->assertTrue($general['name']['required']);

        // A scheme the migration doesn't own keeps its own rule.
        $custom = collect($this->fieldsOf('custom_one'))->keyBy('name');
        $this->assertTrue($custom['description']['required']);
    }
}
