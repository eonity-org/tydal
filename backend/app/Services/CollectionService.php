<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\SearchIndex;
use App\Repositories\Interfaces\CollectionRepositoryInterface;
use App\Services\Interfaces\CollectionServiceInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CollectionService implements CollectionServiceInterface
{
    protected CollectionRepositoryInterface $collectionRepository;

    protected ElasticsearchService $elasticsearch;

    public function __construct(CollectionRepositoryInterface $collectionRepository, ElasticsearchService $elasticsearch)
    {
        $this->collectionRepository = $collectionRepository;
        $this->elasticsearch = $elasticsearch;
    }

    /**
     * Get collections for an organization.
     */
    public function getCollections(string $organizationId, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $query = Collection::where('organization_id', $organizationId);

        return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get collection by ID.
     */
    public function getCollectionById(string $id): ?Collection
    {
        return Collection::find($id);
    }

    /**
     * Create a collection, giving it a search index if the caller did not.
     *
     * A collection with `index_id = null` silently falls back to database
     * search — keyword only, no facets, no semantic retrieval — and nothing
     * anywhere says so. Every collection created through the admin UI landed
     * that way, because the form never sent the field. The scheme already had
     * a default; the index now has the same courtesy.
     */
    public function createCollection(array $data): Collection
    {
        if (empty($data['index_id'])) {
            $data['index_id'] = $this->defaultIndexIdFor($data['organization_id'] ?? null);
        }

        // `collections.slug` is NOT NULL with no default, and this path never
        // produced one — the platform path did, so the omission stayed hidden
        // while collection creation was effectively platform-only. Slugs are
        // unique per organization, so the suffix only counts within one.
        if (empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['name'] ?? 'collection', $data['organization_id'] ?? null);
        }

        $collection = Collection::create($data);

        // Guarantee the index exists the moment a collection needs it, rather
        // than provisioning every scheme's index up front whether or not
        // anything ever uses it. Never `recreate` here — this index may
        // already hold documents from another collection sharing it.
        try {
            $this->elasticsearch->provisionIndex($collection->searchIndex);
        } catch (\Throwable $e) {
            Log::warning("Failed to provision ES index for collection {$collection->id}: {$e->getMessage()}");
        }

        return $collection;
    }

    /** A slug free within this organization, suffixed only if it has to be. */
    private function uniqueSlug(string $name, ?string $organizationId): string
    {
        $base = Str::slug($name) ?: 'collection';
        $slug = $base;

        for ($suffix = 2; Collection::where('organization_id', $organizationId)->where('slug', $slug)->exists(); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    /**
     * The best search index this organization is entitled to.
     *
     * Most specific wins: an index restricted to this organization before a
     * shared one, which is how "give the big customer their own index" works
     * without making per-organization indexes the rule for everyone else.
     */
    public function defaultIndexIdFor(?string $organizationId): ?string
    {
        return SearchIndex::query()
            ->active()
            ->visibleTo($organizationId)
            ->mostSpecificFirst()
            ->orderBy('created_at')
            ->value('id');
    }

    /**
     * Update a collection.
     */
    public function updateCollection(string $id, array $data): ?Collection
    {
        $collection = $this->getCollectionById($id);

        if (! $collection) {
            return null;
        }

        $collection->update($data);

        return $collection->fresh();
    }

    /**
     * Delete a collection.
     */
    public function deleteCollection(string $id): bool
    {
        $collection = $this->getCollectionById($id);

        if (! $collection) {
            return false;
        }

        return $collection->delete();
    }
}
