<?php

namespace Tests\Feature;

use App\Enums\ResourceState;
use App\Models\Collection;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->collection = Collection::factory()->create([
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);

        // Attach user to organization
        $this->organization->users()->attach($this->user->id, [
            'role' => 'admin',
        ]);

        // Set organization context so ResourceController doesn't return 400
        $this->user->update(['last_organization_id' => $this->organization->id]);
    }

    public function test_user_can_list_resources(): void
    {
        Resource::factory()
            ->forCollection($this->collection->id)
            ->count(5)
            ->create([
                'organization_id' => $this->organization->id,
                'user_owner_id' => $this->user->id,
            ]);

        $token = $this->user->createToken('auth-token', ['*'], now()->addHour());

        $response = $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/resources');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'resources' => [
                        '*' => [
                            'id',
                            'name',
                            'type',
                            'created_at',
                        ],
                    ],
                ],
                'meta' => [
                    'pagination',
                ],
                'message',
            ])
            ->assertJsonPath('meta.pagination.total', 5);
    }

    public function test_user_can_create_resource(): void
    {
        $token = $this->user->createToken('auth-token', ['*'], now()->addHour());

        $response = $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/resources', [
                'collection_id' => $this->collection->id,
                'name' => 'Test Resource',
                'description' => 'Test Description',
                'type' => 'document',
                'state' => ResourceState::LIVE->value,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Resource created successfully',
            ]);

        $this->assertDatabaseHas('resources', [
            'name' => 'Test Resource',
            'collection_id' => $this->collection->id,
        ]);
    }

    public function test_user_can_view_resource(): void
    {
        $resource = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);

        $token = $this->user->createToken('auth-token', ['*'], now()->addHour());

        $response = $this->withToken($token->plainTextToken)
            ->getJson("/api/v1/resources/{$resource->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'resource' => [
                        'id' => $resource->id,
                        'name' => $resource->name,
                    ],
                ],
            ]);
    }

    public function test_user_can_update_resource(): void
    {
        $resource = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);

        $token = $this->user->createToken('auth-token', ['*'], now()->addHour());

        $response = $this->withToken($token->plainTextToken)
            ->putJson("/api/v1/resources/{$resource->id}", [
                'name' => 'Updated Resource Name',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Resource updated successfully',
            ]);

        $this->assertDatabaseHas('resources', [
            'id' => $resource->id,
            'name' => 'Updated Resource Name',
        ]);
    }

    public function test_user_can_delete_resource(): void
    {
        $resource = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);

        $token = $this->user->createToken('auth-token', ['*'], now()->addHour());

        $response = $this->withToken($token->plainTextToken)
            ->deleteJson("/api/v1/resources/{$resource->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Resource deleted successfully',
            ]);

        $this->assertSoftDeleted('resources', [
            'id' => $resource->id,
        ]);
    }

    public function test_user_can_upload_file_to_resource(): void
    {
        $resource = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);

        $token = $this->user->createToken('auth-token', ['*'], now()->addHour());

        $file = UploadedFile::fake()->create('document.pdf', 1000);

        $response = $this->withToken($token->plainTextToken)
            ->postJson("/api/v1/resources/{$resource->id}/files", [
                'File' => $file,
                'role' => 'canonical',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'File uploaded successfully',
            ]);

        $this->assertDatabaseHas('files', [
            'resource_id' => $resource->id,
            'filename' => 'document.pdf',
        ]);
    }

    public function test_user_can_get_download_url(): void
    {
        $resource = Resource::factory()->create([
            'collection_id' => $this->collection->id,
            'organization_id' => $this->organization->id,
            'user_owner_id' => $this->user->id,
        ]);

        $file = File::factory()
            ->forResource($resource->id)
            ->create([
                'disk' => 's3',
                'path' => "{$this->organization->id}/{$resource->id}/test.pdf",
            ]);

        Storage::disk('s3')->put($file->path, 'test content');

        $token = $this->user->createToken('auth-token', ['*'], now()->addHour());

        $response = $this->withToken($token->plainTextToken)
            ->getJson("/api/v1/resources/{$resource->id}/files/{$file->id}/download");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'url',
                    'expires_in',
                ],
                'message',
            ]);
    }
}
