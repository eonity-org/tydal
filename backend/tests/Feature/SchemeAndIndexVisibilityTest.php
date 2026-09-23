<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Enums\Visibility;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\SearchIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which organizations a scheme or index is offered to, and what follows.
 *
 * Schemes and indexes are platform-level: neither carries an organization_id.
 * Visibility is how one customer gets a field contract, or an index, of their
 * own without per-organization indexes becoming the rule for everyone.
 *
 * The two are not equal in weight. Scheme visibility curates a menu; index
 * visibility decides whose documents are physically co-resident, which is why
 * the search paths now filter organization explicitly instead of inheriting it
 * from a join.
 */
class SchemeAndIndexVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $organization, OrganizationRole $role): array
    {
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => $role->value]);
        $user->update(['last_organization_id' => $organization->id]);

        return [$user, $user->createToken('t', ['*'], now()->addHour())->plainTextToken];
    }

    private function platformAdmin(Organization $organization): string
    {
        $user = User::factory()->create(['is_superadmin' => true]);
        $user->update(['last_organization_id' => $organization->id]);

        return $user->createToken('t', ['*'], now()->addHour())->plainTextToken;
    }

    private function scheme(string $name, Visibility $visibility = Visibility::GLOBAL, ?Organization $for = null): CollectionScheme
    {
        $scheme = CollectionScheme::create([
            'name' => $name,
            'display_name' => ucfirst($name),
            'fields' => [['name' => 'title', 'type' => 'string']],
            'is_system' => false,
            'visibility' => $visibility,
        ]);

        if ($for) {
            $scheme->organizations()->attach($for->id);
        }

        return $scheme;
    }

    private function index(string $name, Visibility $visibility = Visibility::GLOBAL, ?Organization $for = null): SearchIndex
    {
        $index = SearchIndex::create([
            'index_name' => $name,
            'display_name' => $name,
            'is_active' => true,
            'visibility' => $visibility,
        ]);

        if ($for) {
            $index->organizations()->attach($for->id);
        }

        return $index;
    }

    // ------------------------------------------------------------ the mechanism

    public function test_a_restricted_scheme_is_offered_only_to_its_organizations(): void
    {
        $mine = Organization::factory()->create();
        $theirs = Organization::factory()->create();

        $this->scheme('shared');
        $this->scheme('just_for_them', Visibility::RESTRICTED, $theirs);
        $this->scheme('just_for_me', Visibility::RESTRICTED, $mine);

        [, $token] = $this->member($mine, OrganizationRole::OWNER);

        $names = collect($this->withToken($token)->getJson('/api/v1/collection-schemes')->json('data.schemes'))
            ->pluck('name')->all();

        $this->assertContains('shared', $names);
        $this->assertContains('just_for_me', $names);
        $this->assertNotContains('just_for_them', $names);
    }

    public function test_a_platform_admin_sees_the_whole_catalogue(): void
    {
        $mine = Organization::factory()->create();
        $theirs = Organization::factory()->create();
        $this->scheme('just_for_them', Visibility::RESTRICTED, $theirs);

        $names = collect(
            $this->withToken($this->platformAdmin($mine))
                ->getJson('/api/v1/collection-schemes')->json('data.schemes')
        )->pluck('name')->all();

        // They curate it, so they must be able to see all of it.
        $this->assertContains('just_for_them', $names);
    }

    public function test_index_visibility_follows_the_same_rule(): void
    {
        $mine = Organization::factory()->create();
        $theirs = Organization::factory()->create();

        $this->index('shared_index');
        $this->index('their_index', Visibility::RESTRICTED, $theirs);

        [, $token] = $this->member($mine, OrganizationRole::OWNER);
        $names = collect($this->withToken($token)->getJson('/api/v1/search-indexes')->json('data.indexes'))
            ->pluck('index_name')->all();

        $this->assertContains('shared_index', $names);
        $this->assertNotContains('their_index', $names);
    }

    // ------------------------------------------------- the null-index bug it fixes

    public function test_a_new_collection_gets_the_most_specific_index_available(): void
    {
        $organization = Organization::factory()->create();
        $shared = $this->index('shared_index');
        $own = $this->index('their_own_index', Visibility::RESTRICTED, $organization);

        [, $token] = $this->member($organization, OrganizationRole::OWNER);

        $response = $this->withToken($token)->postJson('/api/v1/collections', [
            'name' => 'Photographs',
            'language' => 'en',
            'scheme_id' => $this->scheme('multimedia_like')->id,
        ]);

        $response->assertStatus(201);

        // Their own index wins over the shared one — that is the whole point of
        // "give the big customer an index without doing it for everyone".
        $this->assertSame($own->id, Collection::find($response->json('data.collection.id'))->index_id);
        $this->assertNotSame($shared->id, $response->json('data.collection.index_id'));
    }

    public function test_a_collection_falls_back_to_the_shared_index(): void
    {
        $organization = Organization::factory()->create();
        $shared = $this->index('shared_index');

        [, $token] = $this->member($organization, OrganizationRole::OWNER);

        $response = $this->withToken($token)->postJson('/api/v1/collections', [
            'name' => 'Documents',
            'language' => 'en',
            'scheme_id' => $this->scheme('docs')->id,
        ])->assertStatus(201);

        // Previously this landed as NULL and the collection silently fell back
        // to database search — no facets, no semantic retrieval, no warning.
        $this->assertSame($shared->id, $response->json('data.collection.index_id'));
    }

    // -------------------------------------------------------------------- quota

    public function test_collection_creation_stops_at_the_quota(): void
    {
        $organization = Organization::factory()->create(['collection_quota' => 2]);
        $this->index('shared_index');
        $scheme = $this->scheme('quota_scheme');
        [, $token] = $this->member($organization, OrganizationRole::OWNER);

        foreach (['One', 'Two'] as $name) {
            $this->withToken($token)->postJson('/api/v1/collections', [
                'name' => $name, 'language' => 'en', 'scheme_id' => $scheme->id,
            ])->assertStatus(201);
        }

        $this->withToken($token)->postJson('/api/v1/collections', [
            'name' => 'Three', 'language' => 'en', 'scheme_id' => $scheme->id,
        ])->assertStatus(422);
    }

    public function test_a_platform_admin_is_not_bound_by_the_quota(): void
    {
        $organization = Organization::factory()->create(['collection_quota' => 0]);
        $this->index('shared_index');
        $scheme = $this->scheme('quota_scheme');

        $this->withToken($this->platformAdmin($organization))
            ->postJson('/api/v1/collections', [
                'name' => 'Above the cap', 'language' => 'en', 'scheme_id' => $scheme->id,
            ])->assertStatus(201);
    }

    public function test_an_editor_cannot_create_a_collection(): void
    {
        $organization = Organization::factory()->create();
        $this->index('shared_index');
        [, $token] = $this->member($organization, OrganizationRole::EDITOR);

        $this->withToken($token)->postJson('/api/v1/collections', [
            'name' => 'Nope', 'language' => 'en', 'scheme_id' => $this->scheme('nope')->id,
        ])->assertStatus(403);
    }

    public function test_a_scheme_from_another_organization_cannot_be_used(): void
    {
        $mine = Organization::factory()->create();
        $theirs = Organization::factory()->create();
        $this->index('shared_index');
        $foreign = $this->scheme('theirs_only', Visibility::RESTRICTED, $theirs);

        [, $token] = $this->member($mine, OrganizationRole::OWNER);

        // Otherwise the visibility list would only be a menu, not a rule.
        $this->withToken($token)->postJson('/api/v1/collections', [
            'name' => 'Borrowed', 'language' => 'en', 'scheme_id' => $foreign->id,
        ])->assertStatus(403);
    }

    // -------------------------------------------------------------------- clone

    public function test_cloning_produces_a_scheme_restricted_to_one_organization(): void
    {
        $organization = Organization::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
        $source = $this->scheme('multimedia');

        $response = $this->withToken($this->platformAdmin($organization))
            ->postJson("/api/v1/collection-schemes/{$source->id}/clone", [
                'organization_id' => $organization->id,
            ])->assertStatus(201);

        $clone = CollectionScheme::find($response->json('data.scheme.id'));

        $this->assertSame($source->fields, $clone->fields);
        $this->assertSame(Visibility::RESTRICTED, $clone->visibility);
        $this->assertFalse($clone->is_system);
        $this->assertTrue($clone->isVisibleTo($organization->id));
        $this->assertFalse($clone->isVisibleTo(Organization::factory()->create()->id));
    }

    public function test_a_scheme_in_use_refuses_field_edits_but_allows_renaming(): void
    {
        $organization = Organization::factory()->create();
        $scheme = $this->scheme('locked');
        Collection::factory()->create([
            'organization_id' => $organization->id,
            'scheme_id' => $scheme->id,
        ]);

        $token = $this->platformAdmin($organization);

        // Changing the contract under live resources would leave the ES mapping
        // disagreeing with the documents — clone instead.
        $this->withToken($token)
            ->putJson("/api/v1/collection-schemes/{$scheme->id}", [
                'fields' => [['name' => 'something_else', 'type' => 'string']],
            ])->assertStatus(422);

        $this->withToken($token)
            ->putJson("/api/v1/collection-schemes/{$scheme->id}", ['display_name' => 'Renamed'])
            ->assertStatus(200);
    }

    public function test_an_unused_scheme_stays_editable(): void
    {
        $organization = Organization::factory()->create();
        $scheme = $this->scheme('fresh');

        $this->withToken($this->platformAdmin($organization))
            ->putJson("/api/v1/collection-schemes/{$scheme->id}", [
                'fields' => [['name' => 'extra_field', 'type' => 'string']],
            ])->assertStatus(200);

        $this->assertSame('extra_field', $scheme->fresh()->fields[0]['name']);
    }
}
