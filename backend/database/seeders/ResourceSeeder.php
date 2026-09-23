<?php

namespace Database\Seeders;

use App\Enums\ResourceState;
use App\Enums\ResourceType;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

/**
 * Resource Seeder
 *
 * Creates test resources with known slugs.
 * Run AFTER MinimalSeeder and CollectionSchemaSeeder.
 */
class ResourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('=====================================');
        $this->command->info('Resource Seeder - Test Resources');
        $this->command->info('=====================================');
        $this->command->newLine();

        // Get organization
        $organization = Organization::where('slug', 'tydal')->first();
        if (! $organization) {
            $this->command->error('Organization "tydal" not found. Run MinimalSeeder first.');

            return;
        }

        // Get superadmin
        $superadmin = User::where('email', 'superadmin@tydal.test')->first();
        if (! $superadmin) {
            $this->command->error('Superadmin not found. Run MinimalSeeder first.');

            return;
        }

        // Get default workspace
        $workspace = Workspace::where('organization_id', $organization->id)->where('is_default', true)->first();
        if (! $workspace) {
            $this->command->error('Default workspace not found. Run MinimalSeeder first.');

            return;
        }

        // Get collection
        $collection = Collection::where('slug', 'tydal-multimedia')->first();
        if (! $collection) {
            $this->command->error('Collection "tydal-multimedia" not found. Run MinimalSeeder first.');

            return;
        }

        // Create test resources
        $resources = [
            [
                'name' => 'Mountain Landscape',
                'slug' => 'mountain-landscape',
                'type' => ResourceType::IMAGE,
                'description' => 'Beautiful mountain landscape at sunset',
                'metadata' => [
                    'author' => 'Nature Photographer',
                    'tags' => ['nature', 'mountain', 'landscape'],
                    'resolution' => '1920x1080',
                ],
            ],
            [
                'name' => 'Ocean Waves',
                'slug' => 'ocean-waves',
                'type' => ResourceType::VIDEO,
                'description' => 'Calm ocean waves on a sandy beach',
                'metadata' => [
                    'author' => 'Beach Lover',
                    'tags' => ['ocean', 'waves', 'beach'],
                    'duration' => '120',
                ],
            ],
            [
                'name' => 'Forest Birds',
                'slug' => 'forest-birds',
                'type' => ResourceType::AUDIO,
                'description' => 'Bird sounds in a dense forest',
                'metadata' => [
                    'author' => 'Audio Recorder',
                    'tags' => ['birds', 'forest', 'nature'],
                    'duration' => '180',
                ],
            ],
        ];

        $createdCount = 0;
        foreach ($resources as $resourceData) {
            $resource = Resource::firstOrCreate(
                ['slug' => $resourceData['slug']],
                [
                    'organization_id' => $organization->id,
                    'collection_id' => $collection->id,
                    'user_owner_id' => $superadmin->id,
                    'type' => $resourceData['type'],
                    'name' => $resourceData['name'],
                    'slug' => $resourceData['slug'],
                    'description' => $resourceData['description'],
                    'state' => ResourceState::LIVE->value,
                    'payload' => [
                        'downloadable' => true,
                        'public' => false,
                    ],
                    'metadata' => $resourceData['metadata'],
                ]
            );

            if ($resource->wasRecentlyCreated) {
                $this->command->info("✓ Resource created: {$resource->name} ({$resource->type->value})");
                $createdCount++;
            } else {
                $this->command->info("⊙ Resource already exists: {$resource->name}");
            }
        }

        $this->command->newLine();
        $this->command->info('=====================================');
        $this->command->info("Resources created: {$createdCount}");
        $this->command->info('=====================================');
        $this->command->newLine();
        $this->command->info('Next steps:');
        $this->command->info('  php artisan db:seed --class=ResourceImageSeeder');
        $this->command->info('  php artisan search:setup-indices --recreate && php artisan search:reindex');
        $this->command->info('=====================================');
    }
}
