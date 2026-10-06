<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Http\Requests\BulkResourceIdsRequest;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The trash and Archived listings clamp `limit` to 1..MAX_IDS (200), the same
 * cap as the bulk endpoints and the catalogue — an unbounded `limit` would let
 * one request load the whole organization.
 */
class ResourceListingLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $organization = Organization::factory()->create();
        $this->admin = User::factory()->create();
        $organization->users()->attach($this->admin->id, ['role' => 'admin']);
        $this->admin->update(['last_organization_id' => $organization->id]);
        $this->token = $this->admin->createToken('auth-token', ['*'], now()->addHour())->plainTextToken;

        $collection = Collection::factory()->create([
            'organization_id' => $organization->id,
            'user_owner_id' => $this->admin->id,
        ]);

        $make = fn (ResourceState $state) => Resource::factory()->forCollection($collection->id)->create([
            'organization_id' => $organization->id,
            'user_owner_id' => $this->admin->id,
            'state' => $state,
        ]);

        $make(ResourceState::ARCHIVED);
        $make(ResourceState::ARCHIVED);
        $make(ResourceState::LIVE)->delete();
        $make(ResourceState::LIVE)->delete();
    }

    /** @return array<string, array{string}> */
    public static function listings(): array
    {
        return [
            'trash' => ['/api/v1/resources/trashed'],
            'archived' => ['/api/v1/resources/archived'],
        ];
    }

    #[DataProvider('listings')]
    public function test_a_huge_limit_is_capped_at_the_bulk_maximum(string $url): void
    {
        $this->withToken($this->token)
            ->getJson($url.'?limit=100000')
            ->assertOk()
            ->assertJsonPath('per_page', BulkResourceIdsRequest::MAX_IDS)
            ->assertJsonCount(2, 'data');

        $this->assertSame(200, BulkResourceIdsRequest::MAX_IDS);
    }

    #[DataProvider('listings')]
    public function test_a_zero_or_negative_limit_becomes_one(string $url): void
    {
        $this->withToken($this->token)
            ->getJson($url.'?limit=0')
            ->assertOk()
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(1, 'data');

        $this->withToken($this->token)
            ->getJson($url.'?limit=-5')
            ->assertOk()
            ->assertJsonPath('per_page', 1);
    }

    #[DataProvider('listings')]
    public function test_the_default_page_size_is_unchanged(string $url): void
    {
        $this->withToken($this->token)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('per_page', 48);
    }
}
