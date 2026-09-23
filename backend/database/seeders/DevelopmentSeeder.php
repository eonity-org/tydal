<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Collection;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SemanticTag;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class DevelopmentSeeder extends Seeder
{
    /**
     * Run the database seeds for development.
     */
    public function run(): void
    {
        // ── Base setup (superadmin + tydal org + workspace + collection) ──────
        $this->call(MinimalSeeder::class);

        $superAdmin = User::where('email', 'superadmin@tydal.test')->firstOrFail();

        // ── Additional random organizations ───────────────────────────────────
        $organizations = Organization::factory()->count(3)->create();

        // ── Users ─────────────────────────────────────────────────────────────
        $users = User::factory()->count(10)->create();

        // Attach users to organizations with different roles
        foreach ($organizations as $org) {
            foreach ($users as $index => $user) {
                $role = match ($index) {
                    0 => 'owner',
                    1, 2 => 'admin',
                    3, 4, 5 => 'editor',
                    default => 'viewer',
                };
                $org->users()->attach($user->id, ['role' => $role]);
            }
        }

        // ── Per-org data ──────────────────────────────────────────────────────
        $organizations->each(function ($org) use ($users) {

            // 1. Create semantic tags FIRST so resource attachment finds them
            $predefinedTags = [
                ['label' => 'Education',          'entity_type' => 'thing'],
                ['label' => 'Technology',         'entity_type' => 'thing'],
                ['label' => 'Science',            'entity_type' => 'thing'],
                ['label' => 'Mathematics',        'entity_type' => 'thing'],
                ['label' => 'Language',           'entity_type' => 'thing'],
                ['label' => 'Art',                'entity_type' => 'thing'],
                ['label' => 'Music',              'entity_type' => 'thing'],
                ['label' => 'History',            'entity_type' => 'thing'],
                ['label' => 'Geography',          'entity_type' => 'place'],
                ['label' => 'Physical Education', 'entity_type' => 'thing'],
            ];

            foreach ($predefinedTags as $tag) {
                SemanticTag::factory()
                    ->forOrganization($org->id)
                    ->withAttributes($tag['label'], $tag['entity_type'])
                    ->create();
            }

            SemanticTag::factory()->count(rand(5, 10))->forOrganization($org->id)->create();

            $orgTags = SemanticTag::where('organization_id', $org->id)->get();

            // 2. Create workspaces — one default (all org resources) + 3 curated
            Workspace::create([
                'organization_id' => $org->id,
                'user_owner_id' => $users->first()->id,
                'name' => 'All Resources',
                'slug' => $org->slug.'-all',
                'description' => 'All resources in this organization',
                'is_active' => true,
                'is_default' => true,
            ]);

            Workspace::factory()
                ->count(3)
                ->forOrganization($org->id)
                ->create(['user_owner_id' => $users->random()->id]);

            // 3. Create collections → categories → resources
            Collection::factory()
                ->count(rand(6, 15))
                ->create([
                    'organization_id' => $org->id,
                    'user_owner_id' => $users->random()->id,
                ])
                ->each(function ($collection) use ($org, $users, $orgTags) {
                    // Root categories
                    $rootCategories = Category::factory()
                        ->count(rand(2, 4))
                        ->forCollection($collection->id)
                        ->create([
                            'organization_id' => $org->id,
                            'user_owner_id' => $users->random()->id,
                        ]);

                    // Sub-categories
                    $rootCategories->each(function ($root) use ($org, $collection, $users) {
                        Category::factory()
                            ->count(rand(1, 3))
                            ->forCollection($collection->id)
                            ->withParent($root->id)
                            ->create([
                                'organization_id' => $org->id,
                                'user_owner_id' => $users->random()->id,
                            ]);
                    });

                    $allCategories = Category::where('collection_id', $collection->id)->get();

                    // Resources
                    Resource::factory()
                        ->count(rand(5, 15))
                        ->forCollection($collection->id)
                        ->create([
                            'organization_id' => $org->id,
                            'user_owner_id' => $users->random()->id,
                        ])
                        ->each(function ($resource) use ($allCategories, $orgTags) {
                            File::factory()
                                ->count(rand(1, 3))
                                ->forResource($resource->id)
                                ->create();

                            $resource->categories()->attach(
                                $allCategories->random(min(rand(1, 3), $allCategories->count()))
                            );

                            if ($orgTags->isNotEmpty()) {
                                $resource->semanticTags()->attach(
                                    $orgTags->random(min(rand(1, 5), $orgTags->count()))
                                );
                            }
                        });
                });
        });

        $this->command->info('Development data seeded successfully!');
        $this->command->info('- Organizations: '.$organizations->count());
        $this->command->info('- Users: '.$users->count());
        $this->command->info('- Workspaces: '.Workspace::count());
        $this->command->info('- Collections: '.Collection::count());
        $this->command->info('- Categories: '.Category::count());
        $this->command->info('- Resources: '.Resource::count());
        $this->command->info('- Files: '.File::count());
        $this->command->info('- Semantic Tags: '.SemanticTag::count());
    }
}
