<?php

namespace Database\Seeders;

use App\Enums\ResourceState;
use App\Enums\ResourceType;
use App\Models\Collection;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SecondOrganizationResourceSeeder extends Seeder
{
    private const PDF_FIXTURE = __DIR__.'/../../tests/fixtures/sample.pdf';

    public function run(): void
    {
        $this->command->info('=====================================');
        $this->command->info('SecondOrg Resource Seeder');
        $this->command->info('=====================================');
        $this->command->newLine();

        $organization = Organization::where('slug', 'secondorg')->first();
        if (! $organization) {
            $this->command->error('Organization "secondorg" not found. Run SecondOrganizationSeeder first.');

            return;
        }

        $admin = User::where('email', 'admin@secondorg.test')->first();
        if (! $admin) {
            $this->command->error('User admin@secondorg.test not found. Run SecondOrganizationSeeder first.');

            return;
        }

        $collection = Collection::where('slug', 'secondorg-general')->first();
        if (! $collection) {
            $this->command->error('Collection "secondorg-general" not found. Run SecondOrganizationSeeder first.');

            return;
        }

        if (! file_exists(self::PDF_FIXTURE)) {
            $this->command->error('Fixture not found: '.self::PDF_FIXTURE);

            return;
        }

        $resource = Resource::firstOrCreate(
            ['slug' => 'secondorg-sample-pdf'],
            [
                'organization_id' => $organization->id,
                'collection_id' => $collection->id,
                'user_owner_id' => $admin->id,
                'type' => ResourceType::DOCUMENT,
                'name' => 'Sample PDF Document',
                'slug' => 'secondorg-sample-pdf',
                'description' => 'Sample PDF used for testing',
                'state' => ResourceState::LIVE->value,
                'payload' => ['downloadable' => true, 'public' => false],
                'metadata' => ['author' => 'Test Author'],
            ]
        );

        if (! $resource->wasRecentlyCreated) {
            $this->command->info('⊙ Resource already exists: '.$resource->name);

            return;
        }

        $this->command->info('✓ Resource created: '.$resource->name);

        // Copy fixture to a temp path so addMedia() can move it without
        // destroying the original test fixture.
        $tmpPath = sys_get_temp_dir().'/sample-'.Str::uuid().'.pdf';
        copy(self::PDF_FIXTURE, $tmpPath);

        $media = $resource
            ->addMedia($tmpPath)
            ->usingFileName('sample.pdf')
            ->withCustomProperties(['description' => 'Sample PDF', 'generated' => false])
            ->toMediaCollection('files');

        File::create([
            'id' => Str::uuid(),
            'resource_id' => $resource->id,
            'user_owner_id' => $admin->id,
            'media_id' => $media->id,
            'filename' => 'sample.pdf',
            'mime_type' => 'application/pdf',
            'size' => $media->size,
            'role' => 'canonical',
            'relation' => null,
            'path' => $media->getPathRelativeToRoot(),
            'disk' => $media->disk,
            'metadata' => ['description' => 'Sample PDF'],
            'is_active' => true,
        ]);

        $this->command->info('✓ File attached: sample.pdf');
        $this->command->newLine();
        $this->command->info('=====================================');
        $this->command->info('Done.');
        $this->command->info('=====================================');
    }
}
