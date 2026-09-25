<?php

namespace Database\Seeders;

use App\Enums\ResourceState;
use App\Enums\TagReviewer;
use App\Enums\TagVocabulary;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\SemanticTag;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultKey;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Full Frame Seeder (Epic E0.2 of the Full Frame roadmap)
 *
 * Seeds everything the Full Frame exhibition platform needs to develop
 * against: a dedicated organization, an images-only "photo exhibition"
 * collection scheme (author/year/technique facets + gallery slot roles),
 * a Submissions workspace with generated demo photographs, and a private
 * gallery vault ("First Frame") with one minted VaultKey.
 *
 * Standalone: php artisan db:seed --class=FullFrameSeeder
 * Safe to re-run (firstOrCreate / updateOrCreate everywhere; files and the
 * vault key are only created once). After seeding run:
 *   php artisan search:setup-indices --index=fullframe_photo --recreate
 *   php artisan search:reindex --collection=<id> && php artisan search:reindex --vault=first-frame
 * (--recreate because a running queue worker indexes the seeded resources
 *  before the schema-derived mapping exists, leaving a dynamic mapping that
 *  conflicts with it)
 */
class FullFrameSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('=====================================');
        $this->command->info('Full Frame Seeder');
        $this->command->info('=====================================');
        $this->command->newLine();

        $index = $this->seedSearchIndex();
        $scheme = $this->seedScheme();
        [$org, $admin] = $this->seedOrganization();
        [, $submissions] = $this->seedWorkspaces($org, $admin);
        $collection = $this->seedCollection($org, $admin, $scheme, $index);
        $this->seedPhotos($org, $admin, $collection, $submissions);
        $vault = $this->seedVault($org, $submissions, $collection);
        $keys = $this->seedVaultKeys($vault);

        $this->summary($org, $vault, $collection, $keys);
    }

    // -------------------------------------------------------------------------
    // Schema layer (global, cross-tenant)
    // -------------------------------------------------------------------------

    private function seedSearchIndex(): SearchIndex
    {
        $index = SearchIndex::updateOrCreate(
            ['index_name' => 'fullframe_photo'],
            [
                'display_name' => 'Full Frame Photo Index',
                'description' => 'Elasticsearch index for Full Frame photo exhibitions',
                'index_mappings' => null,
                'is_active' => true,
            ]
        );

        $this->command->info('✓ Search index: fullframe_photo');

        return $index;
    }

    private function seedScheme(): CollectionScheme
    {
        $scheme = CollectionScheme::updateOrCreate(
            ['name' => 'photo_exhibition'],
            [
                'display_name' => 'Photo Exhibition',
                'description' => 'Images-only scheme for photo exhibitions (Full Frame): author, year and technique as facets',
                'accepted_mimetypes' => ['image/*'],
                'is_system' => false,
                'fields' => [
                    [
                        'name' => 'name',
                        'display_name' => 'Title',
                        'type' => 'string',
                        'required' => true,
                        'storage' => 'column',
                        'is_facet' => false,
                        'display_in_form' => true,
                        'order' => 1,
                        'validators' => ['min_length' => 2, 'max_length' => 255],
                        'es_type' => 'text',
                        'es_fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]],
                    ],
                    [
                        'name' => 'description',
                        'display_name' => 'Description',
                        'type' => 'text',
                        'required' => false,
                        'storage' => 'column',
                        'is_facet' => false,
                        'display_in_form' => true,
                        'order' => 2,
                        'validators' => null,
                        'es_type' => 'text',
                    ],
                    [
                        'name' => 'author',
                        'display_name' => 'Author',
                        'type' => 'string',
                        'required' => true,
                        'storage' => 'metadata',
                        'is_facet' => true,
                        'facet_label' => 'Author',
                        'facet_order' => 1,
                        'display_in_form' => true,
                        'order' => 3,
                        'validators' => ['min_length' => 2, 'max_length' => 255],
                        'es_type' => 'text',
                        'es_fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]],
                        'vault_roles' => ['gallery' => 'credit'],
                    ],
                    [
                        'name' => 'year',
                        'display_name' => 'Year',
                        'type' => 'integer',
                        'required' => false,
                        'storage' => 'metadata',
                        'is_facet' => true,
                        'facet_label' => 'Year',
                        'facet_order' => 3,
                        'display_in_form' => true,
                        'order' => 4,
                        // 1826: the earliest surviving photograph (Niépce)
                        'validators' => ['min_value' => 1826, 'max_value' => 2100],
                        'es_type' => 'integer',
                        'vault_roles' => ['gallery' => 'detail'],
                    ],
                    [
                        'name' => 'technique',
                        'display_name' => 'Technique',
                        'type' => 'select',
                        'required' => false,
                        'storage' => 'metadata',
                        'is_facet' => true,
                        'facet_label' => 'Technique',
                        'facet_order' => 2,
                        'display_in_form' => true,
                        'order' => 5,
                        'validators' => ['in' => [
                            'digital', 'film-35mm', 'medium-format', 'large-format',
                            'instant', 'gelatin-silver', 'cyanotype', 'smartphone',
                            'drone', 'other',
                        ]],
                        'es_type' => 'keyword',
                        'vault_roles' => ['gallery' => 'badge'],
                    ],
                ],
            ]
        );

        $this->command->info('✓ Collection scheme: photo_exhibition (images only)');

        return $scheme;
    }

    // -------------------------------------------------------------------------
    // Tenant layer
    // -------------------------------------------------------------------------

    /** @return array{0: Organization, 1: User} */
    private function seedOrganization(): array
    {
        $email = getenv('FULLFRAME_ADMIN_EMAIL') ?: 'admin@fullframe.test';
        $password = (string) (getenv('FULLFRAME_ADMIN_PASSWORD') ?: '');
        $passwordWasGenerated = $password === '';
        if ($passwordWasGenerated) {
            $password = Str::password(20);
        }

        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Full Frame Admin',
                'email' => $email,
                'password' => bcrypt($password),
                'is_superadmin' => false,
                'is_active' => true,
            ]
        );

        $org = Organization::firstOrCreate(
            ['slug' => 'fullframe'],
            [
                'name' => 'Full Frame',
                'slug' => 'fullframe',
                'description' => 'Full Frame — photo exhibition & voting platform',
                'type' => 'business',
                'is_active' => true,
            ]
        );

        $org->users()->syncWithoutDetaching([$admin->id => ['role' => 'owner']]);
        $admin->last_organization_id = $org->id;
        $admin->save();

        $this->command->info('✓ Organization: Full Frame (fullframe)');
        $this->command->info("✓ Admin: {$email}");
        if ($admin->wasRecentlyCreated && $passwordWasGenerated) {
            $this->command->info("  Password: {$password}");
            $this->command->warn('  ⚠️  Randomly generated — it will NOT be shown again. Store it now.');
            $this->command->warn('  ⚠️  Set FULLFRAME_ADMIN_PASSWORD to control it explicitly.');
        } elseif (! $admin->wasRecentlyCreated) {
            $this->command->info('  Password: (unchanged — user already existed)');
        }
        $this->command->newLine();

        return [$org, $admin];
    }

    /** @return array{0: Workspace, 1: Workspace} */
    private function seedWorkspaces(Organization $org, User $admin): array
    {
        $default = Workspace::firstOrCreate(
            ['slug' => 'fullframe-all'],
            [
                'organization_id' => $org->id,
                'user_owner_id' => $admin->id,
                'name' => 'All Resources',
                'slug' => 'fullframe-all',
                'description' => 'All resources in this organization',
                'is_active' => true,
                'is_default' => true,
            ]
        );

        $submissions = Workspace::firstOrCreate(
            ['slug' => 'fullframe-submissions'],
            [
                'organization_id' => $org->id,
                'user_owner_id' => $admin->id,
                'name' => 'Submissions',
                'slug' => 'fullframe-submissions',
                'description' => 'Exhibition submissions under jury evaluation',
                'is_active' => true,
                'is_default' => false,
            ]
        );

        $this->command->info('✓ Workspaces: All Resources (default) + Submissions');

        return [$default, $submissions];
    }

    private function seedCollection(Organization $org, User $admin, CollectionScheme $scheme, SearchIndex $index): Collection
    {
        $collection = Collection::firstOrCreate(
            ['slug' => 'fullframe-photos'],
            [
                'organization_id' => $org->id,
                'user_owner_id' => $admin->id,
                'name' => 'Exhibition Photos',
                'slug' => 'fullframe-photos',
                'description' => 'Photographs submitted to Full Frame exhibitions',
                'scheme_id' => $scheme->id,
                'index_id' => $index->id,
                'is_active' => true,
            ]
        );

        $this->command->info('✓ Collection: Exhibition Photos (fullframe-photos)');
        $this->command->newLine();

        return $collection;
    }

    // -------------------------------------------------------------------------
    // Demo photographs
    // -------------------------------------------------------------------------

    private function seedPhotos(Organization $org, User $admin, Collection $collection, Workspace $submissions): void
    {
        if (! extension_loaded('gd')) {
            $this->command->warn('GD extension not available — skipping photo generation.');

            return;
        }

        $this->command->info('Seeding demo photographs...');

        $tags = [];
        foreach (['nature', 'urban', 'portrait', 'abstract', 'night', 'sea', 'mountains', 'street', 'minimal', 'light'] as $label) {
            $tags[$label] = SemanticTag::firstOrCreate(
                ['organization_id' => $org->id, 'slug' => Str::slug($label)],
                [
                    'organization_id' => $org->id,
                    'label' => $label,
                    'slug' => Str::slug($label),
                    'entity_type' => 'tag',
                    'is_active' => true,
                    'vocabulary' => TagVocabulary::ORGANIZATION,
                    'reviewer' => TagReviewer::USER,
                ]
            );
        }

        foreach ($this->photoSet() as $photo) {
            $slug = Str::slug($photo['name']);

            $resource = Resource::firstOrCreate(
                ['slug' => $slug],
                [
                    'organization_id' => $org->id,
                    'collection_id' => $collection->id,
                    'user_owner_id' => $admin->id,
                    'name' => $photo['name'],
                    'slug' => $slug,
                    'description' => $photo['description'],
                    'type' => 'image',
                    'state' => ResourceState::LIVE->value,
                    'payload' => ['downloadable' => false, 'public' => false, 'featured' => false],
                    'metadata' => [
                        'author' => $photo['author'],
                        'year' => $photo['year'],
                        'technique' => $photo['technique'],
                    ],
                ]
            );

            // Only the curated workspace gets a pivot row: membership of the
            // default ("All Resources") workspace is computed from the org, not
            // stored — writing it would materialize the very table the design
            // avoids.
            $resource->workspaces()->syncWithoutDetaching([$submissions->id]);
            $resource->semanticTags()->syncWithoutDetaching(
                collect($photo['tags'])->map(fn (string $t) => $tags[$t]->id)->all()
            );

            if ($resource->files()->exists()) {
                $this->command->info("  · {$photo['name']} (already has files — skipped)");

                continue;
            }

            $path = $this->generatePhoto($slug, $photo['palette'], $photo['orientation']);
            if (! $path) {
                $this->command->error("  ✗ {$photo['name']} — image generation failed");

                continue;
            }

            $media = $resource
                ->addMedia($path)
                ->usingFileName("{$slug}.jpg")
                ->withCustomProperties(['generated' => true])
                ->toMediaCollection('files');

            File::create([
                'id' => Str::uuid(),
                'resource_id' => $resource->id,
                'user_owner_id' => $admin->id,
                'media_id' => $media->id,
                'filename' => "{$slug}.jpg",
                'mime_type' => 'image/jpeg',
                'size' => $media->size,
                'role' => 'canonical',
                'relation' => null,
                'path' => $media->getPathRelativeToRoot(),
                'disk' => $media->disk,
                'metadata' => ['generated' => true],
                'usage' => ['snapshot'],
                'is_active' => true,
            ]);

            $this->command->info("  ✓ {$photo['name']} — {$photo['author']}, {$photo['year']} ({$photo['technique']})");
        }

        $this->command->newLine();
    }

    /**
     * 24 demo photographs: 6 authors × 4 works, varied years, techniques,
     * orientations and palettes ([top RGB, bottom RGB] gradient).
     */
    private function photoSet(): array
    {
        $authors = [
            'Lena Okafor' => ['digital', 'drone'],
            'Marc Aubert' => ['film-35mm', 'gelatin-silver'],
            'Sofía Ibarra' => ['medium-format', 'digital'],
            'Yuki Tanabe' => ['instant', 'smartphone'],
            'Ewa Lindqvist' => ['large-format', 'cyanotype'],
            'Daniel Mora' => ['digital', 'film-35mm'],
        ];

        $works = [
            // name, description, year, tags, palette [top, bottom], orientation
            ['Tide Lines', 'Foam tracing the retreat of the tide at dawn.', 2024, ['sea', 'minimal'], [[220, 205, 180], [40, 90, 120]], 'landscape'],
            ['Vertical City', 'Facades dissolving into evening haze.', 2023, ['urban', 'night'], [[25, 25, 60], [200, 120, 60]], 'portrait'],
            ['Quiet Ridge', 'The last light on a snowless ridge.', 2025, ['mountains', 'nature'], [[240, 220, 200], [70, 60, 90]], 'landscape'],
            ['Half Shadow', 'A face split by window light.', 2022, ['portrait', 'light'], [[235, 225, 210], [60, 40, 35]], 'portrait'],
            ['Wet Asphalt', 'Neon bleeding across a rained-out crossing.', 2024, ['street', 'night'], [[15, 20, 40], [180, 60, 110]], 'landscape'],
            ['Salt Fields', 'Geometry of evaporation ponds from above.', 2025, ['abstract', 'sea'], [[250, 240, 230], [190, 100, 80]], 'square'],
            ['North Wall', 'Granite face under moving cloud.', 2021, ['mountains', 'minimal'], [[200, 205, 215], [50, 55, 70]], 'portrait'],
            ['Market Noon', 'Shade cloth patterns over a fruit stall.', 2023, ['street', 'light'], [[245, 210, 150], [120, 70, 40]], 'landscape'],
            ['Fog Terminal', 'Cranes vanishing into harbor fog.', 2022, ['urban', 'sea'], [[210, 215, 220], [90, 100, 115]], 'landscape'],
            ['Ember Study', 'Long exposure of a dying fire.', 2024, ['abstract', 'night'], [[20, 10, 10], [230, 120, 40]], 'square'],
            ['Birch Interval', 'A stand of birches in flat winter light.', 2025, ['nature', 'minimal'], [[235, 235, 230], [120, 130, 110]], 'portrait'],
            ['Underpass', 'Sodium light pooling under the ring road.', 2021, ['urban', 'street'], [[30, 25, 20], [220, 170, 90]], 'landscape'],
            ['Low Tide Mirror', 'Sky doubled on wet sand.', 2023, ['sea', 'light'], [[190, 210, 230], [100, 130, 150]], 'landscape'],
            ['Second Face', 'Portrait through rippled glass.', 2024, ['portrait', 'abstract'], [[225, 215, 205], [80, 70, 80]], 'portrait'],
            ['Contour Farming', 'Terraced fields as drawn lines.', 2025, ['nature', 'abstract'], [[210, 190, 120], [80, 100, 60]], 'square'],
            ['Night Ferry', 'Deck lights against open water.', 2022, ['sea', 'night'], [[10, 15, 30], [140, 160, 180]], 'landscape'],
            ['Scaffold Sky', 'Construction grid against cumulus.', 2023, ['urban', 'minimal'], [[230, 235, 245], [150, 90, 60]], 'portrait'],
            ['Grain Elevator', 'Concrete monolith at the edge of town.', 2021, ['urban', 'abstract'], [[215, 210, 200], [95, 90, 85]], 'portrait'],
            ['First Snow Line', 'Where autumn stops on the slope.', 2024, ['mountains', 'nature'], [[245, 245, 250], [140, 110, 80]], 'landscape'],
            ['Interior with Dust', 'Light shafts in an abandoned mill.', 2022, ['light', 'abstract'], [[190, 175, 150], [45, 40, 35]], 'portrait'],
            ['Crosswind', 'Dunes smoking in a steady blow.', 2025, ['nature', 'minimal'], [[250, 235, 200], [170, 140, 100]], 'landscape'],
            ['Blue Hour Butcher', 'A lit shopfront before opening.', 2023, ['street', 'night'], [[35, 45, 75], [200, 190, 170]], 'landscape'],
            ['Standing Water', 'A flooded quarry gone still.', 2024, ['nature', 'sea'], [[130, 160, 160], [30, 60, 65]], 'square'],
            ['Last Departure', 'Empty platform after the final train.', 2021, ['urban', 'light'], [[50, 45, 55], [235, 200, 160]], 'portrait'],
        ];

        $authorNames = array_keys($authors);
        $set = [];
        foreach ($works as $i => [$name, $description, $year, $workTags, $palette, $orientation]) {
            $author = $authorNames[$i % count($authorNames)];
            $set[] = [
                'name' => $name,
                'description' => $description,
                'author' => $author,
                'year' => $year,
                'technique' => $authors[$author][$i % 2],
                'tags' => $workTags,
                'palette' => $palette,
                'orientation' => $orientation,
            ];
        }

        return $set;
    }

    /**
     * Generate an abstract "photograph": a two-color vertical gradient with
     * translucent shapes, seeded by the slug so re-runs are deterministic.
     */
    private function generatePhoto(string $slug, array $palette, string $orientation): ?string
    {
        [$width, $height] = match ($orientation) {
            'portrait' => [1280, 1920],
            'square' => [1600, 1600],
            default => [1920, 1280],
        };

        $image = imagecreatetruecolor($width, $height);
        if (! $image) {
            return null;
        }

        mt_srand(crc32($slug));

        [[$r1, $g1, $b1], [$r2, $g2, $b2]] = $palette;
        for ($y = 0; $y < $height; $y++) {
            $f = $y / $height;
            $color = imagecolorallocate(
                $image,
                (int) ($r1 + ($r2 - $r1) * $f),
                (int) ($g1 + ($g2 - $g1) * $f),
                (int) ($b1 + ($b2 - $b1) * $f)
            );
            imageline($image, 0, $y, $width, $y, $color);
        }

        for ($i = 0; $i < 14; $i++) {
            $f = mt_rand(30, 90) / 100;
            $shade = imagecolorallocatealpha(
                $image,
                (int) min(255, ($r1 + $r2) / 2 * $f + 40),
                (int) min(255, ($g1 + $g2) / 2 * $f + 40),
                (int) min(255, ($b1 + $b2) / 2 * $f + 40),
                mt_rand(60, 100)
            );
            $x = mt_rand(0, $width);
            $y = mt_rand(0, $height);
            if (mt_rand(0, 1) === 0) {
                imagefilledellipse($image, $x, $y, mt_rand(80, 600), mt_rand(80, 600), $shade);
            } else {
                imagesetthickness($image, mt_rand(2, 24));
                imageline($image, $x, $y, mt_rand(0, $width), mt_rand(0, $height), $shade);
            }
        }

        $path = sys_get_temp_dir()."/fullframe-{$slug}.jpg";
        imagejpeg($image, $path, 88);
        imagedestroy($image);

        return $path;
    }

    // -------------------------------------------------------------------------
    // Vault + key
    // -------------------------------------------------------------------------

    private function seedVault(Organization $org, Workspace $submissions, Collection $collection): Vault
    {
        $vault = Vault::firstOrCreate(
            ['organization_id' => $org->id, 'slug' => 'first-frame'],
            [
                'name' => 'First Frame',
                'slug' => 'first-frame',
                'description' => 'Full Frame demo exhibition — private until the opening',
                'purpose' => VaultPurpose::GALLERY,
                'state' => VaultState::PRIVATE->value,
                'has_public_workspace' => false,
                'is_downloadable' => false,
                'is_active' => true,
            ]
        );

        $submissions->vaults()->syncWithoutDetaching([$vault->id]);

        // Curator uploads (`ingest`) land in Submissions, in the photo
        // collection — the vault decides where, never the consumer.
        $vault->update(['exposure_policy' => array_merge($vault->exposure_policy ?? [], [
            'ingest' => ['workspace_id' => $submissions->id, 'collection_id' => $collection->id],
        ])]);

        $this->command->info("✓ Vault: First Frame (gallery, private, hash: {$vault->hash}) ← Submissions");

        return $vault;
    }

    /**
     * Mint the exhibition's two vault keys — a read key for the jury proxy and a
     * write key for the opening (activate/open/close) and the curator's uploads
     * (ingest/update/withdraw) (VAULT_WRITE_METHODS.md).
     *
     * @return array{read: string, write: string}|null
     */
    private function seedVaultKeys(Vault $vault): ?array
    {
        if (VaultKey::where('vault_id', $vault->id)->exists()) {
            $this->command->info('✓ Vault keys already minted (plaintext shown only at creation)');

            return null;
        }

        [, $read] = VaultKey::mint($vault, 'fullframe-read', ['read']);
        [, $write] = VaultKey::mint($vault, 'fullframe-write', ['w:activate', 'w:open', 'w:close', 'w:ingest', 'w:update', 'w:withdraw']);

        return ['read' => $read, 'write' => $write];
    }

    // -------------------------------------------------------------------------

    /** @param array{read: string, write: string}|null $keys */
    private function summary(Organization $org, Vault $vault, Collection $collection, ?array $keys): void
    {
        $this->command->info('=====================================');
        $this->command->info('Full Frame seed complete');
        $this->command->info('=====================================');
        if ($keys !== null) {
            $this->command->info("Vault hash: {$vault->hash}");
            $this->command->info("Read key  (X-Vault-Key): {$keys['read']}");
            $this->command->info("Write key (opening)    : {$keys['write']}");
            $this->command->warn('⚠️  Store them now — NOT shown again.');
            $this->command->newLine();
            $this->command->info('Full Frame dev env (fullframe/.env) — then `npm run db:seed`:');
            $this->command->info("  DEV_VAULT_HASH={$vault->hash}");
            $this->command->info("  DEV_READ_VAULT_KEY={$keys['read']}");
            $this->command->info("  DEV_WRITE_VAULT_KEY={$keys['write']}");
        }
        $this->command->newLine();
        $this->command->info('Next steps:');
        $this->command->info('  php artisan search:setup-indices --index=fullframe_photo --recreate');
        $this->command->info('    # --recreate: a running queue worker may have dynamic-mapped the index already');
        $this->command->info("  php artisan search:reindex --collection={$collection->id}");
        $this->command->info("  php artisan search:reindex --vault={$vault->slug}   # projected vault index (facets)");
        $this->command->newLine();
        $this->command->info('Try the boundary (private vault → key required):');
        $this->command->info("  curl -H 'X-Vault-Key: tvk_…' http://localhost:8000/v/{$org->slug}/{$vault->slug}/meta");
        $this->command->info("  machine form: http://localhost:8000/h/{$vault->hash}");
        $this->command->info("  gallery app : http://localhost:3010/?vault={$org->slug}/{$vault->slug}");
        $this->command->info('=====================================');
    }
}
