<?php

namespace App\Jobs;

use App\Models\Resource;
use App\Services\ElasticsearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexResourceToElasticsearch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(private readonly string $resourceId) {}

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function handle(ElasticsearchService $es): void
    {
        $resource = Resource::with('collection')->find($this->resourceId);

        if (! $resource || ! $resource->state->isVisible()) {
            return;
        }

        $es->indexResource($resource);

        // Keep per-vault projected indexes live (Epic 3.2)
        $es->indexResourceIntoVaults($resource);
    }
}
