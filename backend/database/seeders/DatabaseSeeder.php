<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Runs the full development stack:
     *   1. CollectionSchemaSeeder — search indexes + collection schemes
     *   2. DevelopmentSeeder     — orgs, users, workspaces, collections, resources
     *   3. ResourceImageSeeder   — sample GD-generated images on one resource
     */
    public function run(): void
    {
        $this->call([
            MinimalSeeder::class,
            CollectionSchemaSeeder::class,
            ResourceSeeder::class,
            ResourceImageSeeder::class,
        ]);
    }
}
