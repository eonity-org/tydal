<?php

namespace Database\Seeders;

use App\Models\CollectionScheme;
use Illuminate\Database\Seeder;

/**
 * The photo exhibition collection scheme — images only, with the details a
 * curator gives each photograph (Full Frame's upload form): title, author,
 * technique, dimensions and description, plus year.
 *
 * Global and idempotent, so any organization can put a "Photos" collection on
 * it (`php artisan db:seed --class=PhotoSchemeSeeder`); FullFrameSeeder uses it
 * for its demo. The `vault_roles` place each field on a gallery vault's cards:
 * `author` is the credit under the title, `year` / `technique` / `dimensions`
 * are the details, and `name` / `description` are the caption built-ins.
 * Updating an existing scheme recomposes the indexes of the vaults using it.
 */
class PhotoSchemeSeeder extends Seeder
{
    public const NAME = 'photo_exhibition';

    public function run(): void
    {
        self::apply();

        $this->command->info('✓ Collection scheme: '.self::NAME.' (images only)');
    }

    /** Create or update the scheme — also used by `exhibitions:setup`. */
    public static function apply(): CollectionScheme
    {
        return CollectionScheme::updateOrCreate(
            ['name' => self::NAME],
            [
                'display_name' => 'Photo Exhibition',
                'description' => 'Images-only scheme for photo exhibitions (Full Frame): author, year, technique and dimensions',
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
                        // Free text as the curator writes it ("Silver gelatin
                        // print") — a fixed list would reject real curation.
                        // Still a facet, on the exact wording.
                        'name' => 'technique',
                        'display_name' => 'Technique',
                        'type' => 'string',
                        'required' => false,
                        'storage' => 'metadata',
                        'is_facet' => true,
                        'facet_label' => 'Technique',
                        'facet_order' => 2,
                        'display_in_form' => true,
                        'order' => 5,
                        'validators' => ['max_length' => 255],
                        'es_type' => 'text',
                        'es_fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]],
                        'vault_roles' => ['gallery' => 'detail'],
                    ],
                    [
                        'name' => 'dimensions',
                        'display_name' => 'Dimensions',
                        'type' => 'string',
                        'required' => false,
                        'storage' => 'metadata',
                        'is_facet' => false,
                        'display_in_form' => true,
                        'order' => 6,
                        'validators' => ['max_length' => 255],
                        // text + keyword: what Elasticsearch maps a free-text
                        // metadata value to on its own, so a shared index that
                        // already saw `dimensions` takes this without a rebuild.
                        'es_type' => 'text',
                        'es_fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]],
                        'vault_roles' => ['gallery' => 'detail'],
                    ],
                ],
            ]
        );
    }
}
