<?php

namespace App\Services\Interfaces;

use App\Enums\FileRelation;
use App\Enums\FileRole;
use App\Exceptions\InvalidResourceComposition;
use App\Models\File;
use App\Models\Resource;
use Illuminate\Pagination\LengthAwarePaginator;

interface ResourceServiceInterface
{
    /**
     * Get resources for an organization.
     */
    public function getResources(string $organizationId, array $filters, int $perPage, int $page): LengthAwarePaginator;

    /**
     * Get resource by ID.
     */
    public function getResourceById(string $id): ?Resource;

    /**
     * Create a new resource.
     */
    public function createResource(array $data): Resource;

    /**
     * Update a resource.
     */
    public function updateResource(string $id, array $data): ?Resource;

    /**
     * Delete a resource.
     */
    public function deleteResource(string $id): bool;

    public function permanentlyDelete(\App\Models\Resource $resource): void;

    /**
     * Enforce composition invariants before a file joins a resource
     * (single canonical, no component beside a canonical, snapshot
     * exclusivity); returns the normalized relation.
     *
     * @throws InvalidResourceComposition
     */
    public function applyFileRoleInvariants(Resource $resource, FileRole $role, ?FileRelation $relation, ?array $usage): ?FileRelation;

    /**
     * Set a file as the canonical file for its resource, demoting any existing canonical.
     * Also recalculates promoted metadata and triggers an ES re-index.
     */
    public function setCanonical(Resource $resource, File $file): void;

    /**
     * Apply a non-promotion role/relation change and refresh every derived
     * artifact (metadata, embedding, ES document) — the demotion twin of
     * setCanonical(). Promotion must go through setCanonical().
     *
     * @param  array{role?: string, relation?: string|null}  $validated
     */
    public function updateFileRole(Resource $resource, File $file, array $validated): void;

    /**
     * Transfer the snapshot role to $file, clearing it from all other files.
     */
    public function setSnapshot(Resource $resource, File $file): void;

    /**
     * Guarantee a single valid snapshot (rendering its preview if needed), resolving it
     * deterministically when none or several files are starred. Idempotent; run at commit time.
     */
    public function ensureSnapshot(Resource $resource): void;

    /**
     * Merge tika_metadata from the contributing files (canonical, or all
     * components equally) into Resource.promoted_file_metadata.
     */
    public function recalculatePromotedMetadata(Resource $resource): void;

    /**
     * Files whose metadata/text represent the resource: the active canonical
     * alone, or all active components in manifest order.
     *
     * @return list<string>
     */
    public function metadataContributorIds(Resource $resource): array;

    /**
     * Recompute the resource-level mean embedding from the contributing
     * files' chunk vectors (§8) and persist it.
     */
    public function recalculateResourceEmbedding(Resource $resource): void;

    /**
     * Purge a file's derived artifacts (on-disk archives, FileChunk rows, SystemFile rows)
     * before the file itself is deleted. Call before $file->delete().
     */
    public function purgeFileArtifacts(File $file): void;
}
