<?php

namespace App\Console\Commands;

use App\Models\Collection;
use App\Models\Resource;
use Illuminate\Console\Command;

class DebugMappings extends Command
{
    protected $signature = 'debug:mappings {slug}';

    protected $description = 'Debug scheme fields and ES index for a collection';

    public function handle()
    {
        $slug = $this->argument('slug');
        $collection = Collection::with(['scheme', 'searchIndex'])->where('slug', $slug)->first();

        if (! $collection) {
            $this->error("Collection '{$slug}' not found");

            return;
        }

        $this->info("Collection: {$collection->name}");
        $this->info('ES Index: '.($collection->searchIndex?->index_name ?? 'none'));
        $this->newLine();

        $scheme = $collection->scheme;
        if (! $scheme) {
            $this->warn('No scheme attached to this collection.');

            return;
        }

        $this->info("Scheme: {$scheme->name}");
        $this->newLine();

        $this->info('Fields:');
        foreach ($scheme->fields ?? [] as $field) {
            $facet = ($field['is_facet'] ?? false) ? ' [facet]' : '';
            $this->line("  {$field['name']} ({$field['type']}) storage={$field['storage']}{$facet}");
        }

        $this->newLine();
        $this->info('Testing with first resource:');

        $resource = Resource::where('collection_id', $collection->id)->first();

        if (! $resource) {
            $this->warn('No resources found');

            return;
        }

        $this->line("Resource ID: {$resource->id}");
        $this->line("Resource Name: {$resource->name}");
        $this->line("Resource Type: {$resource->type->value}");

        $this->newLine();
        $this->info('Resource Metadata:');
        foreach ($resource->metadata ?? [] as $key => $value) {
            $this->line("  {$key}: ".json_encode($value));
        }
    }
}
