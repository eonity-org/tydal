<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ProductionSeeder extends Seeder
{
    /**
     * Run the database seeds for production setup.
     *
     * Creates:
     * - Superadmin user
     * - TYDAL organization
     *
     * Usage:
     *   php artisan db:seed --class=ProductionSeeder
     */
    public function run(): void
    {
        $this->command->info('=====================================');
        $this->command->info('Production Seeder');
        $this->command->info('=====================================');

        // Resolve the superadmin password from the environment so no known
        // default ships with the source. If TYDAL_SUPERADMIN_PASSWORD is not
        // set, generate a strong random one and display it once below.
        $email = env('TYDAL_SUPERADMIN_EMAIL', 'superadmin@tydal.test');
        $password = (string) env('TYDAL_SUPERADMIN_PASSWORD', '');
        $passwordWasGenerated = $password === '';
        if ($passwordWasGenerated) {
            $password = Str::password(20);
        }

        // Create superadmin user
        $superAdmin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Platform Superadmin',
                'email' => $email,
                'password' => Hash::make($password),
                'is_superadmin' => true,
                'is_active' => true,
            ]
        );

        // Enforce the configured password on EXISTING users too. firstOrCreate only
        // sets the password when the row is created, so a database that survived a
        // re-install (e.g. a Docker volume that outlived `docker compose down`)
        // would otherwise keep its old password and silently ignore .env. When a
        // password is explicitly set via TYDAL_SUPERADMIN_PASSWORD we re-apply it so
        // the credential always matches the environment. A generated password is NOT
        // re-applied to an existing user (that would randomise a working login every
        // run); set TYDAL_SUPERADMIN_PASSWORD to control an existing account.
        $passwordWasEnforced = false;
        if (! $superAdmin->wasRecentlyCreated && ! $passwordWasGenerated) {
            $superAdmin->password = Hash::make($password);
            $passwordWasEnforced = true;
        }

        // Set last_organization_id to the org we're about to create
        $superAdmin->last_organization_id = null; // Will update after org creation
        $superAdmin->save();

        $this->command->info('');
        $this->command->info($superAdmin->wasRecentlyCreated ? '✅ Superadmin User Created:' : '✅ Superadmin User:');
        $this->command->info("   Email:    {$superAdmin->email}");
        if ($passwordWasGenerated && $superAdmin->wasRecentlyCreated) {
            $this->command->info("   Password: {$password}");
            $this->command->warn('   ⚠️  This password was randomly generated and will NOT be shown again. Store it now.');
            $this->command->warn('   ⚠️  Set TYDAL_SUPERADMIN_PASSWORD in your environment to control it explicitly.');
        } elseif ($superAdmin->wasRecentlyCreated) {
            $this->command->info('   Password: (from TYDAL_SUPERADMIN_PASSWORD)');
            $this->command->warn('   ⚠️  IMPORTANT: rotate this password after first login.');
        } elseif ($passwordWasEnforced) {
            $this->command->info('   Password: (reset to TYDAL_SUPERADMIN_PASSWORD on existing user)');
            $this->command->warn('   ⚠️  IMPORTANT: rotate this password after first login.');
        } else {
            $this->command->info('   Password: (unchanged — existing user; set TYDAL_SUPERADMIN_PASSWORD to reset)');
        }

        // Create TYDAL organization
        $organization = Organization::firstOrCreate(
            ['slug' => 'tydal'],
            [
                'name' => 'TYDAL',
                'slug' => 'tydal',
                'description' => 'TYDAL — Schema-Driven Semantic Vaults for AI Agents',
                'type' => 'business',
                'logo_url' => null,
                'website_url' => 'https://tydal.test',
                'settings' => null,
                'is_active' => true,
            ]
        );

        // Attach superadmin to TYDAL organization as owner
        $organization->users()->syncWithoutDetaching([
            $superAdmin->id => [
                'role' => 'owner',
            ],
        ]);

        // Set superadmin's last organization to TYDAL
        $superAdmin->last_organization_id = $organization->id;
        $superAdmin->save();

        $this->command->info('');
        $this->command->info('✅ TYDAL Organization Created:');
        $this->command->info("   ID:       {$organization->id}");
        $this->command->info("   Name:     {$organization->name}");
        $this->command->info("   Slug:     {$organization->slug}");
        $this->command->info("   Type:     {$organization->type}");

        $this->command->info('');
        $this->command->info('=====================================');
        $this->command->info('Production seeding completed!');
        $this->command->info('=====================================');
        $this->command->info('');
        $this->command->info('📝 Next Steps:');
        $this->command->info('   1. Login with superadmin credentials');
        $this->command->info('   2. Change the superadmin password');
        $this->command->info('   3. Create workspaces and collections');
        $this->command->info('   4. Add additional users if needed');
        $this->command->info('');
        $this->command->info('💡 To seed collection schemas, run:');
        $this->command->info('   php artisan db:seed --class=CollectionSchemaSeeder');
        $this->command->info('');
    }
}
