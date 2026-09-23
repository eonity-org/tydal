<?php

namespace Database\Seeders;

use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\Organization;
use App\Models\SearchIndex;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class SecondOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('=====================================');
        $this->command->info('Second Organization Seeder');
        $this->command->info('=====================================');
        $this->command->newLine();

        // Admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@secondorg.test'],
            [
                'name' => 'Second Org Admin',
                'email' => 'admin@secondorg.test',
                'password' => bcrypt('secondorg123'),
                'is_superadmin' => false,
                'is_active' => true,
            ]
        );

        // Organization
        $organization = Organization::firstOrCreate(
            ['slug' => 'secondorg'],
            [
                'name' => 'secondorg',
                'slug' => 'secondorg',
                'description' => 'Second organization',
                'type' => 'business',
                'is_active' => true,
            ]
        );

        // Attach admin as owner
        $organization->users()->syncWithoutDetaching([
            $admin->id => ['role' => 'admin'],
        ]);

        // Set admin's last organization
        $admin->last_organization_id = $organization->id;
        $admin->save();

        $this->command->info('✓ Organization: secondorg');
        $this->command->info('✓ Admin: admin@secondorg.test');
        $this->command->newLine();

        // Default workspace
        Workspace::firstOrCreate(
            ['slug' => 'secondorg-all'],
            [
                'organization_id' => $organization->id,
                'user_owner_id' => $admin->id,
                'name' => 'All Resources',
                'slug' => 'secondorg-all',
                'description' => 'All resources in this organization',
                'is_active' => true,
                'is_default' => true,
            ]
        );

        $this->command->info('✓ Workspace: All Resources (default)');
        $this->command->newLine();

        // Collection using existing multimedia scheme and index
        $scheme = CollectionScheme::where('name', 'multimedia')->first();
        $index = SearchIndex::where('index_name', 'tydal_multimedia')->first();

        Collection::firstOrCreate(
            ['slug' => 'secondorg-general'],
            [
                'organization_id' => $organization->id,
                'user_owner_id' => $admin->id,
                'name' => 'General',
                'slug' => 'secondorg-general',
                'description' => 'General collection for secondorg',
                'scheme_id' => $scheme?->id,
                'index_id' => $index?->id,
                'is_active' => true,
            ]
        );

        $this->command->info('✓ Collection: General');
        $this->command->newLine();

        $this->command->info('=====================================');
        $this->command->info('Login credentials:');
        $this->command->info('  Email:    admin@secondorg.test');
        $this->command->info('  Password: secondorg123');
        $this->command->info('=====================================');
    }
}
