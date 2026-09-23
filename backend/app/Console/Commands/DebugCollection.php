<?php

namespace App\Console\Commands;

use App\Models\Collection;
use Illuminate\Console\Command;

class DebugCollection extends Command
{
    protected $signature = 'debug:collection {slug}';

    protected $description = 'Debug collection info';

    public function handle()
    {
        $slug = $this->argument('slug');
        $collection = Collection::with(['scheme', 'searchIndex'])->where('slug', $slug)->first();

        if (! $collection) {
            $this->error("Collection '{$slug}' not found");

            return;
        }

        $this->info('Collection: '.$collection->name);
        $this->info('Slug: '.$collection->slug);
        $this->info('Scheme: '.($collection->scheme?->name ?? 'none'));
        $this->info('ES Index: '.($collection->searchIndex?->index_name ?? 'none'));
        $this->info('Organization ID: '.$collection->organization_id);
        $this->info('Active: '.($collection->is_active ? 'Yes' : 'No'));

        $this->newLine();
        $this->info('Resources count: '.$collection->resources()->count());
    }
}
