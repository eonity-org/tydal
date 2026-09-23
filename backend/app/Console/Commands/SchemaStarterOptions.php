<?php

namespace App\Console\Commands;

use App\Models\CollectionScheme;
use Illuminate\Console\Command;

/**
 * List system collection schemes eligible as installer starter collections.
 *
 * The single source of truth `tools/deploy/first_install.sh` reads to build
 * its "which collection(s) should be created" menu (which always leads with
 * "0) None", defaulted to — see first_install.sh) — add a scheme in
 * CollectionSchemaSeeder (is_system = true) and it becomes selectable at
 * install time without touching the shell script.
 *
 * Output is machine-readable: one `name|display_name|description` line per
 * scheme, no header, pipes in description swapped for slashes so the format
 * never breaks. Order: the three core schemes first, in their usual pick
 * order (multimedia, documents, general), then anything else alphabetically —
 * a custom scheme still needs no edit here to show up, just after the core three.
 */
class SchemaStarterOptions extends Command
{
    protected $signature = 'schema:starter-options';

    protected $description = 'List system collection schemes eligible as installer starter collections';

    /** Core schemes' fixed menu position; anything else sorts after, alphabetically. */
    private const CORE_ORDER = ['multimedia' => 0, 'documents' => 1, 'general' => 2];

    public function handle(): int
    {
        CollectionScheme::where('is_system', true)
            ->get(['name', 'display_name', 'description'])
            ->sortBy(fn (CollectionScheme $scheme) => [self::CORE_ORDER[$scheme->name] ?? count(self::CORE_ORDER), $scheme->name])
            ->each(function (CollectionScheme $scheme) {
                $description = str_replace('|', '/', (string) $scheme->description);
                $this->line("{$scheme->name}|{$scheme->display_name}|{$description}");
            });

        return self::SUCCESS;
    }
}
