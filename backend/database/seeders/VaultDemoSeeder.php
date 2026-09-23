<?php

namespace Database\Seeders;

use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use App\Models\Vault;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * VaultDemoSeeder
 *
 * Creates two workspaces (ws1, ws2), a Vault named "prueba",
 * one resource attached to ws1, and ws1 associated with the Vault.
 *
 * Requires MinimalSeeder to have run first (TYDAL org + collection must exist).
 * Safe to re-run: all creates use firstOrCreate.
 */
class VaultDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('=====================================');
        $this->command->info('Vault Demo Seeder');
        $this->command->info('=====================================');

        // ── Resolve org + superadmin ──────────────────────────────────────────

        $org = Organization::where('slug', 'tydal')->firstOrFail();
        $superadmin = User::where('email', 'superadmin@tydal.test')->firstOrFail();
        $collection = Collection::where('slug', 'tydal-multimedia')->firstOrFail();

        // ── Create workspaces ─────────────────────────────────────────────────

        $ws1 = Workspace::firstOrCreate(
            ['slug' => 'tydal-ws1'],
            [
                'organization_id' => $org->id,
                'user_owner_id' => $superadmin->id,
                'name' => 'ws1',
                'slug' => 'tydal-ws1',
                'description' => 'Demo workspace 1',
                'is_active' => true,
                'is_default' => false,
            ]
        );

        $ws2 = Workspace::firstOrCreate(
            ['slug' => 'tydal-ws2'],
            [
                'organization_id' => $org->id,
                'user_owner_id' => $superadmin->id,
                'name' => 'ws2',
                'slug' => 'tydal-ws2',
                'description' => 'Demo workspace 2',
                'is_active' => true,
                'is_default' => false,
            ]
        );

        $this->command->info("✓ Workspace: {$ws1->name} (id: {$ws1->id})");
        $this->command->info("✓ Workspace: {$ws2->name} (id: {$ws2->id})");

        // ── Create Vault "prueba" ───────────────────────────────────────────────

        $vault = Vault::firstOrCreate(
            ['organization_id' => $org->id, 'slug' => 'prueba'],
            [
                'name' => 'prueba',
                'slug' => 'prueba',
                'description' => 'Demo Vault for testing',
                'purpose' => VaultPurpose::DELIVERY,
                'state' => VaultState::PRIVATE->value,
                'salt' => Str::random(32),
                'has_public_workspace' => false,
                'is_downloadable' => true,
                'hash_ttl_hours' => null,
                'allowed_ips' => null,
                'base_url' => null,
                'is_active' => true,
            ]
        );

        $this->command->info("✓ Vault: {$vault->name} (id: {$vault->id}, purpose: {$vault->purpose->value}, hash: {$vault->hash})");

        // ── One demo vault per purpose (Epic 2.1) ─────────────────────────────

        foreach (VaultPurpose::cases() as $purpose) {
            $slug = "demo-{$purpose->value}";

            $purposeVault = Vault::firstOrCreate(
                ['organization_id' => $org->id, 'slug' => $slug],
                [
                    'name' => "Demo {$purpose->label()}",
                    'slug' => $slug,
                    'description' => "Demo vault with purpose '{$purpose->value}'",
                    'purpose' => $purpose,
                    'state' => VaultState::PRIVATE->value,
                    'salt' => Str::random(32),
                    'has_public_workspace' => false,
                    'is_downloadable' => true,
                    'is_active' => true,
                ]
            );

            $this->command->info("✓ Vault: {$purposeVault->name} (purpose: {$purpose->value}, hash: {$purposeVault->hash})");
        }

        // ── Create one resource and attach it to ws1 ──────────────────────────

        $resource = Resource::firstOrCreate(
            ['slug' => 'vault-demo-resource'],
            [
                'organization_id' => $org->id,
                'collection_id' => $collection->id,
                'user_owner_id' => $superadmin->id,
                'name' => 'Vault Demo Resource',
                'slug' => 'vault-demo-resource',
                'description' => 'A demo resource for Vault link generation',
                'type' => 'image',
                'state' => ResourceState::LIVE->value,
                'payload' => ['downloadable' => true, 'public' => false, 'featured' => false],
            ]
        );

        $resource->workspaces()->syncWithoutDetaching([$ws1->id]);

        $this->command->info("✓ Resource: {$resource->name} (id: {$resource->id}) → attached to ws1");

        // ── Associate ws1 with the Vault ────────────────────────────────────────

        $ws1->vaults()->syncWithoutDetaching([$vault->id]);

        $this->command->info("✓ ws1 associated with Vault \"{$vault->name}\"");

        // ── Summary ───────────────────────────────────────────────────────────

        $this->command->newLine();
        $this->command->info('Done! You can now open the resource in the UI and go to the Vault tab.');
        $this->command->info('  Resource slug : vault-demo-resource');
        $this->command->info("  Vault tab will generate links via Vault \"{$vault->name}\" × ws1");
        $this->command->info('=====================================');
    }
}
