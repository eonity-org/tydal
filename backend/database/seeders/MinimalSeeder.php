<?php

namespace Database\Seeders;

use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\SearchIndex;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ElasticsearchService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Minimal Seeder for Testing
 *
 * Unconditionally creates the minimum data needed for TYDAL to be usable:
 * superadmin, one org, one default workspace. Optionally also creates one
 * starter collection per scheme name in TYDAL_INITIAL_COLLECTIONS
 * (comma-separated; unset/empty creates none — the org/superadmin/workspace
 * baseline alone is enough to log in and use TYDAL).
 * Run CollectionSchemaSeeder first, then ResourceSeeder after this.
 */
class MinimalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('=====================================');
        $this->command->info('Minimal Seeder - Test Data');
        $this->command->info('=====================================');
        $this->command->newLine();

        // Resolve the superadmin password from the environment so no known
        // default ships in source. Blank => generate a strong random one and
        // print it once below.
        $email = env('TYDAL_SUPERADMIN_EMAIL', 'superadmin@tydal.test');
        $password = (string) env('TYDAL_SUPERADMIN_PASSWORD', '');
        $passwordWasGenerated = $password === '';
        if ($passwordWasGenerated) {
            $password = Str::password(20);
        }

        // Get or create superadmin
        $superadmin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Platform Superadmin',
                'email' => $email,
                'password' => bcrypt($password),
                'is_superadmin' => true,
                'is_active' => true,
            ]
        );

        // Get or create TYDAL organization
        $organization = Organization::firstOrCreate(
            ['slug' => 'tydal'],
            [
                'name' => 'TYDAL',
                'slug' => 'tydal',
                'description' => 'TYDAL Digital Asset Management Platform',
                'type' => 'business',
                'logo_url' => null,
                'website_url' => 'https://tydal.test',
                'settings' => null,
                'is_active' => true,
            ]
        );

        // Attach superadmin to TYDAL organization as owner
        $organization->users()->syncWithoutDetaching([
            $superadmin->id => ['role' => 'owner'],
        ]);

        // Set superadmin's last organization
        $superadmin->last_organization_id = $organization->id;
        $superadmin->save();

        $this->command->info('✓ Organization: TYDAL');
        $this->command->info("✓ Superadmin: {$email}");
        $this->command->newLine();

        // Create default workspace (all org resources)
        $workspace = Workspace::firstOrCreate(
            ['slug' => 'tydal-all'],
            [
                'organization_id' => $organization->id,
                'name' => 'All Resources',
                'slug' => 'tydal-all',
                'description' => 'All resources in this organization',
                'user_owner_id' => $superadmin->id,
                'is_active' => true,
                'is_default' => true,
            ]
        );

        $this->command->info('✓ Workspace: All Resources (default)');
        $this->command->newLine();

        // Create the requested starter collection(s) — one per scheme name in
        // TYDAL_INITIAL_COLLECTIONS (comma-separated; defaults to "multimedia"
        // so existing installs/scripts that don't set it are unaffected).
        $requested = array_filter(array_map('trim', explode(
            ',', (string) env('TYDAL_INITIAL_COLLECTIONS', 'multimedia')
        )));

        $createdCollections = [];
        foreach ($requested as $schemeName) {
            // DatabaseSeeder deliberately runs this before CollectionSchemaSeeder
            // (see CollectionSchemaSeeder::wireCollections) — the scheme/index
            // may not exist yet. Create the collection anyway with whatever is
            // resolvable now; wireCollections() back-fills scheme_id/index_id
            // once the seeder that owns them has run.
            $scheme = CollectionScheme::where('name', $schemeName)->first();
            $index = SearchIndex::where('index_name', "tydal_{$schemeName}")->first();
            $slug = "tydal-{$schemeName}";
            $displayName = $scheme->display_name ?? ucfirst($schemeName).' Collection';

            Collection::firstOrCreate(
                ['slug' => $slug],
                [
                    'organization_id' => $organization->id,
                    'user_owner_id' => $superadmin->id,
                    'name' => $displayName,
                    'slug' => $slug,
                    'description' => "Default {$displayName} for TYDAL",
                    'scheme_id' => $scheme?->id,
                    'index_id' => $index?->id,
                    'is_active' => true,
                ]
            );

            // Only provisionable if the index already existed when this ran;
            // otherwise wireCollections() provisions it once it does.
            // `recreate: true` is safe here — this only ever follows a fresh
            // migrate, so there is nothing indexed yet to lose.
            if ($index) {
                app(ElasticsearchService::class)->provisionIndex($index, recreate: true);
            }

            $createdCollections[] = $displayName;
            $this->command->info("✓ Collection: {$displayName}");
        }
        $this->command->newLine();

        $this->command->info('=====================================');
        $this->command->info('Minimal test data created successfully!');
        $this->command->info('=====================================');
        $this->command->newLine();
        $this->command->info('Data created:');
        $this->command->info('  - Organization: TYDAL');
        $this->command->info("  - Superadmin: {$email}");
        $this->command->info('  - Workspace: All Resources (default)');
        if (empty($createdCollections)) {
            $this->command->info('  - Collections: none (minimal install — create one from the UI, or');
            $this->command->info('    re-run: TYDAL_INITIAL_COLLECTIONS=multimedia php artisan db:seed --class=MinimalSeeder)');
        }
        foreach ($createdCollections as $collectionName) {
            $this->command->info("  - Collection: {$collectionName}");
        }
        $this->command->newLine();
        $this->command->info('Login credentials:');
        $this->command->info("  Email: {$email}");
        if (! $superadmin->wasRecentlyCreated) {
            $this->command->info('  Password: (unchanged — user already existed)');
        } elseif ($passwordWasGenerated) {
            $this->command->info("  Password: {$password}");
            $this->command->warn('  ⚠️  Randomly generated — it will NOT be shown again. Store it now.');
            $this->command->warn('  ⚠️  Set TYDAL_SUPERADMIN_PASSWORD to control it explicitly.');
        } else {
            $this->command->info('  Password: (from TYDAL_SUPERADMIN_PASSWORD)');
        }
        $this->command->newLine();
        $this->command->info('Useful commands:');
        $this->command->info('  php artisan queue:work --timeout=360                          # required for async indexing/enrichment');
        $this->command->info('  php artisan search:reconcile                                  # check MySQL <-> Elasticsearch drift');
        $this->command->info('  php artisan db:seed --class=ResourceSeeder                    # optional: a few known demo resources');
        $this->command->info('  php artisan db:seed --class=ResourceImageSeeder               # optional: sample media for them');
        $this->command->info('  php artisan mcp:token --org=SLUG --abilities=read,ask,write   # issue an org-mcp access token');
        $this->command->info('=====================================');
    }
}
