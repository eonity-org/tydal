<?php

namespace Database\Seeders;

use App\Enums\ResourceState;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Models\Collection;
use App\Models\CollectionScheme;
use App\Models\File;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\SearchIndex;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultKey;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * ImageLab Seeder — the `ai` vault counterpart of {@see FullFrameSeeder}.
 *
 * Stands up everything the ImageLab pipeline (imagelab/) needs to run
 * end-to-end against a dev TYDAL:
 *   - an org (imagelab) + admin;
 *   - a source "figures" collection with a few generated diagram-like images;
 *   - a SOURCE `ai` vault over those figures with exposure_policy overrides:
 *       allow_binary = true                (so the external AI can fetch pixels)
 *       ingest = { workspace_id, collection_id }   (where write-back lands)
 *     and two minted keys — a read key (['read']) and a write key (['w:ingest']);
 *   - an OUTPUT workspace + collection where `ingest` composes each result
 *     (canonical descriptor.json + component translated image);
 *   - an OUTPUT `delivery` vault over that workspace so the renderer can pull
 *     the JSON + images as public links (P5 export).
 *
 * Standalone:  php artisan db:seed --class=ImageLabSeeder
 * Safe to re-run (firstOrCreate / updateOrCreate; files + keys once).
 *
 * The summary prints a ready-to-paste imagelab/.env block.
 */
class ImageLabSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('=====================================');
        $this->command->info('ImageLab Seeder (ai vault + ingest)');
        $this->command->info('=====================================');
        $this->command->newLine();

        $index = $this->seedSearchIndex();
        $figuresScheme = $this->seedFiguresScheme();
        $outputScheme = $this->seedOutputScheme();
        [$org, $admin] = $this->seedOrganization();
        [, $figures, $output] = $this->seedWorkspaces($org, $admin);
        $figuresCollection = $this->seedFiguresCollection($org, $admin, $figuresScheme, $index);
        $outputCollection = $this->seedOutputCollection($org, $admin, $outputScheme);
        $this->seedFigures($org, $admin, $figuresCollection, $figures);

        $source = $this->seedSourceVault($org, $figures, $output, $outputCollection);
        $delivery = $this->seedOutputVault($org, $output);
        $keys = $this->seedSourceKeys($source);

        $this->summary($org, $source, $delivery, $figuresCollection, $keys);
    }

    // -------------------------------------------------------------------------
    // Schema layer (global, cross-tenant)
    // -------------------------------------------------------------------------

    private function seedSearchIndex(): SearchIndex
    {
        $index = SearchIndex::updateOrCreate(
            ['index_name' => 'imagelab_figure'],
            [
                'display_name' => 'ImageLab Figure Index',
                'description' => 'Elasticsearch index for ImageLab source figures',
                'index_mappings' => null,
                'is_active' => true,
            ]
        );

        $this->command->info('✓ Search index: imagelab_figure');

        return $index;
    }

    private function seedFiguresScheme(): CollectionScheme
    {
        $scheme = CollectionScheme::updateOrCreate(
            ['name' => 'imagelab_figure'],
            [
                'display_name' => 'Textbook Figure',
                'description' => 'Images-only source scheme for ImageLab — diagrams, graphs, tables and formulae the external AI reads.',
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
                        'name' => 'kind',
                        'display_name' => 'Figure kind',
                        'type' => 'select',
                        'required' => false,
                        'storage' => 'metadata',
                        'is_facet' => true,
                        'facet_label' => 'Kind',
                        'facet_order' => 1,
                        'display_in_form' => true,
                        'order' => 3,
                        'validators' => ['in' => ['table', 'graph', 'formula', 'diagram', 'mixed']],
                        'es_type' => 'keyword',
                    ],
                    [
                        'name' => 'subject',
                        'display_name' => 'Subject',
                        'type' => 'string',
                        'required' => false,
                        'storage' => 'metadata',
                        'is_facet' => true,
                        'facet_label' => 'Subject',
                        'facet_order' => 2,
                        'display_in_form' => true,
                        'order' => 4,
                        'validators' => ['max_length' => 120],
                        'es_type' => 'text',
                        'es_fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]],
                    ],
                ],
            ]
        );

        $this->command->info('✓ Collection scheme: imagelab_figure (images only)');

        return $scheme;
    }

    /**
     * Output scheme — the ingested figures land here (canonical descriptor.json
     * + a component translated image). Accepts JSON + images; the writer bypasses
     * form-level mimetype gating, but keeping this honest documents the payload.
     */
    private function seedOutputScheme(): CollectionScheme
    {
        $scheme = CollectionScheme::updateOrCreate(
            ['name' => 'imagelab_output'],
            [
                'display_name' => 'ImageLab Output',
                'description' => 'Ingested results: a canonical JSON descriptor the renderer addresses + the translated image.',
                'accepted_mimetypes' => ['application/json', 'image/*'],
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
                        'validators' => ['min_length' => 1, 'max_length' => 255],
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
                ],
            ]
        );

        $this->command->info('✓ Collection scheme: imagelab_output (json + image)');

        return $scheme;
    }

    // -------------------------------------------------------------------------
    // Tenant layer
    // -------------------------------------------------------------------------

    /** @return array{0: Organization, 1: User} */
    private function seedOrganization(): array
    {
        $email = getenv('IMAGELAB_ADMIN_EMAIL') ?: 'admin@imagelab.test';
        $password = (string) (getenv('IMAGELAB_ADMIN_PASSWORD') ?: '');
        $passwordWasGenerated = $password === '';
        if ($passwordWasGenerated) {
            $password = Str::password(20);
        }

        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'ImageLab Admin',
                'email' => $email,
                'password' => bcrypt($password),
                'is_superadmin' => false,
                'is_active' => true,
            ]
        );

        $org = Organization::firstOrCreate(
            ['slug' => 'imagelab'],
            [
                'name' => 'ImageLab',
                'slug' => 'imagelab',
                'description' => 'ImageLab — AI figure translation & descriptor extraction',
                'type' => 'business',
                'is_active' => true,
            ]
        );

        $org->users()->syncWithoutDetaching([$admin->id => ['role' => 'owner']]);
        $admin->last_organization_id = $org->id;
        $admin->save();

        // Enroll the platform superadmin as an owner too, so the org is
        // reachable from the SPA's org switcher (which lists only orgs you
        // belong to). Without this the seeded vault looks "linked to a
        // non-existent organization" from a superadmin session, even though
        // the DB link is valid. Mirrors how fullframe is reachable.
        $superadmin = User::where('email', getenv('TYDAL_SUPERADMIN_EMAIL') ?: 'superadmin@tydal.test')
            ->orWhere('is_superadmin', true)
            ->first();
        if ($superadmin && $superadmin->id !== $admin->id) {
            $org->users()->syncWithoutDetaching([$superadmin->id => ['role' => 'owner']]);
            $this->command->info("✓ Superadmin enrolled in ImageLab ({$superadmin->email})");
        }

        $this->command->info('✓ Organization: ImageLab (imagelab)');
        $this->command->info("✓ Admin: {$email}");
        if ($admin->wasRecentlyCreated && $passwordWasGenerated) {
            $this->command->info("  Password: {$password}");
            $this->command->warn('  ⚠️  Randomly generated — NOT shown again. Store it now.');
            $this->command->warn('  ⚠️  Set IMAGELAB_ADMIN_PASSWORD to control it explicitly.');
        } elseif (! $admin->wasRecentlyCreated) {
            $this->command->info('  Password: (unchanged — user already existed)');
        }
        $this->command->newLine();

        return [$org, $admin];
    }

    /** @return array{0: Workspace, 1: Workspace, 2: Workspace} */
    private function seedWorkspaces(Organization $org, User $admin): array
    {
        $default = Workspace::firstOrCreate(
            ['slug' => 'imagelab-all'],
            [
                'organization_id' => $org->id,
                'user_owner_id' => $admin->id,
                'name' => 'All Resources',
                'slug' => 'imagelab-all',
                'description' => 'All resources in this organization',
                'is_active' => true,
                'is_default' => true,
            ]
        );

        $figures = Workspace::firstOrCreate(
            ['slug' => 'imagelab-figures'],
            [
                'organization_id' => $org->id,
                'user_owner_id' => $admin->id,
                'name' => 'Source Figures',
                'slug' => 'imagelab-figures',
                'description' => 'Textbook figures the external AI reads (source of the ai vault)',
                'is_active' => true,
                'is_default' => false,
            ]
        );

        $output = Workspace::firstOrCreate(
            ['slug' => 'imagelab-output'],
            [
                'organization_id' => $org->id,
                'user_owner_id' => $admin->id,
                'name' => 'Textbook Output',
                'slug' => 'imagelab-output',
                'description' => 'Ingested descriptors + translated images (delivery source for the renderer)',
                'is_active' => true,
                'is_default' => false,
            ]
        );

        $this->command->info('✓ Workspaces: All Resources (default) + Source Figures + Textbook Output');

        return [$default, $figures, $output];
    }

    private function seedFiguresCollection(Organization $org, User $admin, CollectionScheme $scheme, SearchIndex $index): Collection
    {
        $collection = Collection::firstOrCreate(
            ['slug' => 'imagelab-figures'],
            [
                'organization_id' => $org->id,
                'user_owner_id' => $admin->id,
                'name' => 'Source Figures',
                'slug' => 'imagelab-figures',
                'description' => 'Diagrams, graphs, tables and formulae for AI translation',
                'scheme_id' => $scheme->id,
                'index_id' => $index->id,
                'is_active' => true,
            ]
        );

        $this->command->info('✓ Collection: Source Figures (imagelab-figures)');

        return $collection;
    }

    private function seedOutputCollection(Organization $org, User $admin, CollectionScheme $scheme): Collection
    {
        $collection = Collection::firstOrCreate(
            ['slug' => 'imagelab-output'],
            [
                'organization_id' => $org->id,
                'user_owner_id' => $admin->id,
                'name' => 'Textbook Output',
                'slug' => 'imagelab-output',
                'description' => 'Ingested descriptor.json + translated image per figure',
                'scheme_id' => $scheme->id,
                // No ES index: the renderer addresses these by vault link, and
                // ingest writes bypass the search path. DB is source of truth.
                'index_id' => null,
                'is_active' => true,
            ]
        );

        $this->command->info('✓ Collection: Textbook Output (imagelab-output)');
        $this->command->newLine();

        return $collection;
    }

    // -------------------------------------------------------------------------
    // Demo figures
    // -------------------------------------------------------------------------

    private function seedFigures(Organization $org, User $admin, Collection $collection, Workspace $figures): void
    {
        if (! extension_loaded('gd')) {
            $this->command->warn('GD extension not available — skipping figure generation.');

            return;
        }

        $this->command->info('Seeding demo figures...');

        foreach ($this->figureSet() as $figure) {
            $slug = Str::slug($figure['name']);

            $resource = Resource::firstOrCreate(
                ['slug' => $slug],
                [
                    'organization_id' => $org->id,
                    'collection_id' => $collection->id,
                    'user_owner_id' => $admin->id,
                    'name' => $figure['name'],
                    'slug' => $slug,
                    'description' => $figure['description'],
                    'type' => 'image',
                    'state' => ResourceState::LIVE->value,
                    'payload' => ['downloadable' => false, 'public' => false, 'featured' => false],
                    'metadata' => [
                        'kind' => $figure['kind'],
                        'subject' => $figure['subject'],
                    ],
                ]
            );

            $resource->workspaces()->syncWithoutDetaching([$figures->id]);

            if ($resource->files()->exists()) {
                $this->command->info("  · {$figure['name']} (already has files — skipped)");

                continue;
            }

            $path = $this->generateFigure($slug, $figure['kind'], $figure['palette']);
            if (! $path) {
                $this->command->error("  ✗ {$figure['name']} — image generation failed");

                continue;
            }

            $media = $resource
                ->addMedia($path)
                ->usingFileName("{$slug}.png")
                ->withCustomProperties(['generated' => true])
                ->toMediaCollection('files');

            File::create([
                'id' => Str::uuid(),
                'resource_id' => $resource->id,
                'user_owner_id' => $admin->id,
                'media_id' => $media->id,
                'filename' => "{$slug}.png",
                'mime_type' => 'image/png',
                'size' => $media->size,
                'role' => 'canonical',
                'relation' => null,
                'path' => $media->getPathRelativeToRoot(),
                'disk' => $media->disk,
                'metadata' => ['generated' => true],
                'usage' => ['snapshot'],
                'is_active' => true,
            ]);

            $this->command->info("  ✓ {$figure['name']} — {$figure['kind']} · {$figure['subject']}");
        }

        $this->command->newLine();
    }

    /**
     * A small, varied source set: tables, graphs, formulae and a diagram —
     * the figure kinds the descriptor schema (imagelab/docs/DESCRIPTOR_SCHEMA.md)
     * models. Palette is [top RGB, bottom RGB] for the background wash.
     */
    private function figureSet(): array
    {
        return [
            ['name' => 'Periodic Trends Table', 'description' => 'Electronegativity across periods 2–4.', 'kind' => 'table', 'subject' => 'Chemistry', 'palette' => [[245, 245, 250], [210, 220, 235]]],
            ['name' => 'Population Growth Curve', 'description' => 'Logistic growth to carrying capacity.', 'kind' => 'graph', 'subject' => 'Biology', 'palette' => [[250, 248, 240], [225, 235, 210]]],
            ['name' => 'Quadratic Formula Panel', 'description' => 'Derivation of the quadratic roots.', 'kind' => 'formula', 'subject' => 'Mathematics', 'palette' => [[248, 244, 250], [230, 215, 235]]],
            ['name' => 'Water Cycle Diagram', 'description' => 'Evaporation, condensation, precipitation.', 'kind' => 'diagram', 'subject' => 'Earth Science', 'palette' => [[240, 248, 252], [205, 228, 240]]],
            ['name' => 'Force Vectors Graph', 'description' => 'Resultant of two perpendicular forces.', 'kind' => 'graph', 'subject' => 'Physics', 'palette' => [[250, 246, 244], [235, 220, 210]]],
            ['name' => 'Cell Cycle Table', 'description' => 'Phases and checkpoints of mitosis.', 'kind' => 'table', 'subject' => 'Biology', 'palette' => [[246, 250, 246], [215, 235, 218]]],
        ];
    }

    /**
     * Generate a schematic "figure": a soft vertical wash with a title bar and
     * kind-specific marks (grid lines for tables, a curve for graphs, glyph
     * blocks for formulae, connected nodes for diagrams). Seeded by slug so
     * re-runs are deterministic. Uses PNG (crisp lines) at textbook aspect.
     */
    private function generateFigure(string $slug, string $kind, array $palette): ?string
    {
        [$width, $height] = [1400, 1000];

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

        $ink = imagecolorallocate($image, 40, 45, 60);
        $accent = imagecolorallocate($image, 150, 26, 44); // TYDAL burgundy
        imagesetthickness($image, 3);

        // Title bar
        imagefilledrectangle($image, 60, 50, $width - 60, 130, $accent);

        // Frame
        imagerectangle($image, 60, 160, $width - 60, $height - 60, $ink);

        switch ($kind) {
            case 'table':
                for ($c = 1; $c < 6; $c++) {
                    $x = 60 + (int) (($width - 120) * $c / 6);
                    imageline($image, $x, 160, $x, $height - 60, $ink);
                }
                for ($r = 1; $r < 8; $r++) {
                    $y = 160 + (int) (($height - 220) * $r / 8);
                    imageline($image, 60, $y, $width - 60, $y, $ink);
                }
                break;

            case 'graph':
                // Axes
                imagesetthickness($image, 4);
                imageline($image, 180, $height - 140, 180, 240, $ink);
                imageline($image, 180, $height - 140, $width - 140, $height - 140, $ink);
                // A rising curve
                imagesetthickness($image, 5);
                $px = 180;
                $py = $height - 160;
                for ($t = 0; $t <= 100; $t++) {
                    $x = 180 + (int) (($width - 360) * $t / 100);
                    $y = ($height - 160) - (int) (($height - 420) * (1 - exp(-$t / 25)));
                    imageline($image, $px, $py, $x, $y, $accent);
                    $px = $x;
                    $py = $y;
                }
                break;

            case 'formula':
                imagesetthickness($image, 6);
                // Fraction bar + glyph blocks suggesting an equation
                imageline($image, 360, 540, $width - 360, 540, $ink);
                for ($i = 0; $i < 6; $i++) {
                    $x = 400 + $i * 150;
                    imagefilledrectangle($image, $x, 400, $x + 90, 480, $ink);
                    imagefilledrectangle($image, $x, 600, $x + 90, 680, ($i % 2) ? $accent : $ink);
                }
                break;

            case 'diagram':
            default:
                imagesetthickness($image, 4);
                $nodes = [[300, 350], [700, 300], [1050, 420], [500, 700], [900, 720]];
                foreach ($nodes as $j => [$nx, $ny]) {
                    foreach ($nodes as $k => [$mx, $my]) {
                        if ($k > $j && (($j + $k) % 2 === 0)) {
                            imageline($image, $nx, $ny, $mx, $my, $ink);
                        }
                    }
                }
                foreach ($nodes as [$nx, $ny]) {
                    imagefilledellipse($image, $nx, $ny, 70, 70, $accent);
                }
                break;
        }

        $path = sys_get_temp_dir()."/imagelab-{$slug}.png";
        imagepng($image, $path, 6);
        imagedestroy($image);

        return $path;
    }

    // -------------------------------------------------------------------------
    // Vaults + keys
    // -------------------------------------------------------------------------

    /**
     * The SOURCE `ai` vault. Two exposure_policy overrides make it usable by
     * ImageLab: allow_binary (the AI fetches image pixels — off by default on
     * `ai`) and the ingest target (where write-back composes the output
     * resource). See VAULT_SYSTEM.md §6.5 and VAULT_WRITE_METHODS.md §7.
     */
    private function seedSourceVault(Organization $org, Workspace $figures, Workspace $output, Collection $outputCollection): Vault
    {
        $vault = Vault::firstOrCreate(
            ['organization_id' => $org->id, 'slug' => 'figures'],
            [
                'name' => 'Textbook Figures',
                'slug' => 'figures',
                'description' => 'ImageLab source — AI reads these figures and writes back descriptors',
                'purpose' => VaultPurpose::AI,
                'state' => VaultState::PRIVATE->value,
                'has_public_workspace' => false,
                'is_downloadable' => false,
                'exposure_policy' => [
                    'allow_binary' => true,
                    'ingest' => [
                        'workspace_id' => $output->id,
                        'collection_id' => $outputCollection->id,
                    ],
                ],
            ]
        );

        // Keep the override current even if the vault pre-existed with stale ids.
        $vault->exposure_policy = [
            'allow_binary' => true,
            'ingest' => [
                'workspace_id' => $output->id,
                'collection_id' => $outputCollection->id,
            ],
        ];
        $vault->save();

        $figures->vaults()->syncWithoutDetaching([$vault->id]);

        $this->command->info("✓ Source vault: Textbook Figures (ai, private, binary ON, hash: {$vault->hash}) ← Source Figures");
        $this->command->info("  ingest → workspace #{$output->id} / collection #{$outputCollection->id}");

        return $vault;
    }

    /**
     * The OUTPUT `delivery` vault over the output workspace — public links the
     * separate HTML renderer pulls (JSON descriptor + translated image).
     */
    private function seedOutputVault(Organization $org, Workspace $output): Vault
    {
        $vault = Vault::firstOrCreate(
            ['organization_id' => $org->id, 'slug' => 'textbook-delivery'],
            [
                'name' => 'Textbook Delivery',
                'slug' => 'textbook-delivery',
                'description' => 'ImageLab output — descriptors + translated images for the HTML renderer',
                'purpose' => VaultPurpose::DELIVERY,
                'state' => VaultState::PUBLIC->value,
                'has_public_workspace' => true,
                'is_downloadable' => true,
            ]
        );

        $output->vaults()->syncWithoutDetaching([$vault->id]);

        $this->command->info("✓ Output vault: Textbook Delivery (delivery, public, hash: {$vault->hash}) ← Textbook Output");

        return $vault;
    }

    /**
     * Mint the source vault's two keys — a read key (enumerate + fetch binary)
     * and a write key (`w:ingest`) for the pipeline's write-back.
     *
     * @return array{read: string, write: string}|null
     */
    private function seedSourceKeys(Vault $vault): ?array
    {
        if (VaultKey::where('vault_id', $vault->id)->exists()) {
            $this->command->info('✓ Source vault keys already minted (plaintext shown only at creation)');

            return null;
        }

        [, $read] = VaultKey::mint($vault, 'imagelab-read', ['read']);
        [, $write] = VaultKey::mint($vault, 'imagelab-ingest', ['w:ingest']);

        return ['read' => $read, 'write' => $write];
    }

    // -------------------------------------------------------------------------

    /** @param array{read: string, write: string}|null $keys */
    private function summary(Organization $org, Vault $source, Vault $delivery, Collection $figuresCollection, ?array $keys): void
    {
        $this->command->info('=====================================');
        $this->command->info('ImageLab seed complete');
        $this->command->info('=====================================');
        $this->command->info("Source vault hash: {$source->hash}");
        $this->command->info("Output vault     : {$org->slug}/{$delivery->slug} (public)");
        if ($keys !== null) {
            $this->command->info("Read key  (X-Vault-Key): {$keys['read']}");
            $this->command->info("Write key (w:ingest)   : {$keys['write']}");
            $this->command->warn('⚠️  Store them now — NOT shown again.');
        }
        $this->command->newLine();

        $this->command->info('Index the source figures (so the vault can list them):');
        $this->command->info('  php artisan search:setup-indices --index=imagelab_figure --recreate');
        $this->command->info("  php artisan search:reindex --collection={$figuresCollection->id}");
        $this->command->info("  php artisan search:reindex --vault={$source->slug}");
        $this->command->newLine();

        if ($keys !== null) {
            $this->command->info('imagelab/.env — paste this block:');
            $this->command->info('  TYDAL_BASE_URL=http://host.docker.internal:8000');
            $this->command->info('  ADAPTER=stub');
            $this->command->info('  IMAGELAB_DB=./data/imagelab.db');
            $this->command->info('  IMAGELAB_BIND_NAME=textbook-figures');
            $this->command->info("  SOURCE_ORG={$org->slug}");
            $this->command->info("  SOURCE_SLUG={$source->slug}");
            $this->command->info("  SOURCE_HASH={$source->hash}");
            $this->command->info("  READ_KEY={$keys['read']}");
            $this->command->info("  WRITE_KEY={$keys['write']}");
            $this->command->info("  OUTPUT_ORG={$org->slug}");
            $this->command->info("  OUTPUT_SLUG={$delivery->slug}");
            $this->command->info('  BINDING_ID=1');
            $this->command->info('  # (generate the encryption key: openssl rand -base64 32)');
            $this->command->info('  IMAGELAB_ENCRYPTION_KEY=');
            $this->command->newLine();
            $this->command->info('Then, from imagelab/:');
            $this->command->info('  ./dev.sh db      # create the sqlite store');
            $this->command->info('  ./dev.sh bind    # validates read /meta + ingest capability, stores keys');
            $this->command->info('  ./dev.sh run     # read → stub AI → write(ingest)  (ADAPTER=claude for the real model)');
        }
        $this->command->info('=====================================');
    }
}
