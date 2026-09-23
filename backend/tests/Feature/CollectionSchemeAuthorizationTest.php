<?php

namespace Tests\Feature;

use App\Models\CollectionScheme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Collection schemes (and search indexes) are GLOBAL, cross-tenant
 * infrastructure. Reads are open to any authenticated user; writes are
 * superadmin-only — a tenant must never mutate a shared object other tenants
 * depend on. Field names are also charset-constrained at the boundary because
 * they flow into raw SQL in the catalogue's DB-facet path.
 */
class CollectionSchemeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $regularUser;

    private string $superAdminToken;

    private string $regularToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->regularUser = User::factory()->create();
        $this->superAdminToken = $this->superAdmin->createToken('test')->plainTextToken;
        $this->regularToken = $this->regularUser->createToken('test')->plainTextToken;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'test-scheme',
            'display_name' => 'Test Scheme',
            'fields' => [
                ['name' => 'author', 'type' => 'text'],
            ],
        ], $overrides);
    }

    // ── Authorization ────────────────────────────────────────────────────────

    public function test_regular_user_can_read_schemes(): void
    {
        $this->withToken($this->regularToken)
            ->getJson('/api/v1/collection-schemes')
            ->assertStatus(200);
    }

    public function test_regular_user_cannot_create_a_scheme(): void
    {
        $this->withToken($this->regularToken)
            ->postJson('/api/v1/collection-schemes', $this->validPayload())
            ->assertStatus(403);

        $this->assertDatabaseMissing('collection_schemes', ['name' => 'test-scheme']);
    }

    public function test_regular_user_cannot_update_a_scheme(): void
    {
        $scheme = CollectionScheme::create($this->validPayload() + ['is_system' => false]);

        $this->withToken($this->regularToken)
            ->putJson("/api/v1/collection-schemes/{$scheme->id}", ['display_name' => 'Hijacked'])
            ->assertStatus(403);
    }

    public function test_regular_user_cannot_delete_a_scheme(): void
    {
        $scheme = CollectionScheme::create($this->validPayload() + ['is_system' => false]);

        $this->withToken($this->regularToken)
            ->deleteJson("/api/v1/collection-schemes/{$scheme->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('collection_schemes', ['id' => $scheme->id]);
    }

    public function test_regular_user_cannot_write_search_indexes(): void
    {
        $this->withToken($this->regularToken)
            ->postJson('/api/v1/search-indexes', ['index_name' => 'evil', 'display_name' => 'Evil'])
            ->assertStatus(403);
    }

    public function test_superadmin_can_create_a_scheme(): void
    {
        $this->withToken($this->superAdminToken)
            ->postJson('/api/v1/collection-schemes', $this->validPayload())
            ->assertStatus(201);

        $this->assertDatabaseHas('collection_schemes', ['name' => 'test-scheme']);
    }

    // ── Field-name charset (defense-in-depth vs the raw-SQL facet path) ───────

    public function test_field_name_with_sql_metacharacters_is_rejected(): void
    {
        $this->withToken($this->superAdminToken)
            ->postJson('/api/v1/collection-schemes', $this->validPayload([
                'fields' => [
                    ['name' => "x') IS NOT NULL OR (SELECT 1", 'type' => 'text'],
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.name');
    }

    public function test_field_name_with_uppercase_or_spaces_is_rejected(): void
    {
        $this->withToken($this->superAdminToken)
            ->postJson('/api/v1/collection-schemes', $this->validPayload([
                'fields' => [['name' => 'Author Name', 'type' => 'text']],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.name');
    }
}
