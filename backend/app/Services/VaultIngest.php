<?php

namespace App\Services;

use App\Enums\FileRelation;
use App\Enums\FileRole;
use App\Enums\VaultCapability;
use App\Models\Collection;
use App\Models\File;
use App\Models\Resource;
use App\Models\Vault;
use App\Models\VaultLink;
use App\Models\VaultWrite;
use App\Models\Workspace;
use App\Services\Interfaces\ResourceServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The purpose-agnostic half of the inbound write ops (VAULT_WRITE_METHODS.md
 * §3): `ingest` / `update` / `withdraw`. Each purpose's writer decides only
 * which files an `ingest` carries and how they're stored; everything here is
 * shared by every purpose:
 *
 * - **Where** ingested resources land — vault-configured, never
 *   consumer-supplied:
 *     exposure_policy = { "ingest": { "workspace_id": 12, "collection_id": 5 } }
 * - **The metadata document.** The consumer sends one flat `metadata` object;
 *   keys naming a resource column (`name`, `description`) are lifted into it,
 *   the rest are stored in `resources.metadata` as given. No AI and no scheme
 *   check touch it — the scheme only decides which keys get indexed/faceted.
 * - **Provenance.** `update`/`withdraw` act only on resources this vault's
 *   `ingest` created; the `vault_writes` audit is the record.
 */
class VaultIngest
{
    /** Keys lifted from the metadata document into resource columns. */
    public const COLUMNS = ['name', 'description'];

    private const MAX_KEYS = 50;

    private const MAX_VALUE_LENGTH = 5000;

    public function __construct(
        private ResourceServiceInterface $resources,
    ) {}

    /**
     * The vault-configured landing spot. Both must exist and belong to the
     * vault's org.
     *
     * @return array{0: Workspace, 1: Collection}
     */
    public function target(Vault $vault): array
    {
        $target = $vault->exposure_policy[VaultCapability::INGEST->value] ?? null;
        $workspaceId = is_array($target) ? ($target['workspace_id'] ?? null) : null;
        $collectionId = is_array($target) ? ($target['collection_id'] ?? null) : null;

        if (! $workspaceId || ! $collectionId) {
            throw ValidationException::withMessages([
                'vault' => 'This vault has no ingest target — set exposure_policy.ingest.workspace_id and .collection_id.',
            ]);
        }

        $workspace = Workspace::where('organization_id', $vault->organization_id)->find($workspaceId);
        $collection = Collection::where('organization_id', $vault->organization_id)->find($collectionId);

        if (! $workspace || ! $collection) {
            throw ValidationException::withMessages([
                'vault' => 'The configured ingest workspace/collection was not found in this vault\'s organization.',
            ]);
        }

        return [$workspace, $collection];
    }

    /**
     * The largest file an `ingest` accepts, in bytes: TYDAL's own media limit
     * (MAX_MEDIA_FILE_SIZE) bounded by what PHP will receive at all. Reported by
     * the write probe so a consumer can refuse a file before uploading it.
     */
    public function maxUploadBytes(): int
    {
        $limits = [
            (int) config('media-library.max_file_size'),
            self::iniBytes((string) ini_get('upload_max_filesize')),
            self::iniBytes((string) ini_get('post_max_size')),
        ];

        return min(array_filter($limits, fn (int $bytes) => $bytes > 0) ?: [PHP_INT_MAX]);
    }

    /** "300M" → bytes (php.ini shorthand); 0 means unlimited. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** Refuse a file over the limit with a message a curator can act on. */
    public function assertSize(UploadedFile $file): void
    {
        $max = $this->maxUploadBytes();
        if ((int) $file->getSize() > $max) {
            $mb = fn (int $bytes) => number_format($bytes / 1024 / 1024, 1);
            throw ValidationException::withMessages([
                'image' => "The file is {$mb((int) $file->getSize())} MB; this vault accepts up to {$mb($max)} MB.",
            ]);
        }
    }

    /**
     * Validate a metadata document and split it into resource columns and the
     * `resources.metadata` remainder. Flat only: every value is a string,
     * number, boolean or null (null means "remove" on update, "absent" on
     * ingest).
     *
     * @return array{columns: array<string, string|null>, metadata: array<string, scalar|null>}
     */
    public function document(mixed $document): array
    {
        if (is_string($document)) {
            $document = json_decode($document, true);
        }
        $document ??= [];

        if (! is_array($document) || ($document !== [] && array_is_list($document))) {
            throw ValidationException::withMessages(['metadata' => 'metadata must be a JSON object.']);
        }
        if (count($document) > self::MAX_KEYS) {
            throw ValidationException::withMessages(['metadata' => 'metadata may hold at most '.self::MAX_KEYS.' keys.']);
        }

        $columns = [];
        $metadata = [];
        foreach ($document as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
                throw ValidationException::withMessages(['metadata' => "Invalid metadata key: {$key}. Use lowercase letters, digits and underscores."]);
            }
            if ($value !== null && ! is_scalar($value)) {
                throw ValidationException::withMessages(['metadata' => "metadata.{$key} must be text, a number or a boolean."]);
            }
            if (is_string($value)) {
                $value = trim($value);
                $max = $key === 'name' ? 255 : self::MAX_VALUE_LENGTH;
                if (mb_strlen($value) > $max) {
                    throw ValidationException::withMessages(['metadata' => "metadata.{$key} is longer than {$max} characters."]);
                }
            }

            if (in_array($key, self::COLUMNS, true)) {
                $columns[$key] = $value === null || $value === '' ? null : (string) $value;
            } else {
                $metadata[$key] = $value === '' ? null : $value;
            }
        }

        return ['columns' => $columns, 'metadata' => $metadata];
    }

    /**
     * Attach a file from a local path to a resource via MediaLibrary and record
     * the File row (no request/user context — this runs behind the vault key).
     *
     * @param  list<string>|null  $usage
     */
    public function attachFile(
        Resource $resource,
        string $path,
        string $filename,
        FileRole $role,
        ?FileRelation $relation,
        ?array $usage,
    ): File {
        $media = $resource->addMedia($path)
            ->usingFileName($filename)
            ->withCustomProperties(['role' => $role->value])
            ->toMediaCollection('files');

        return $resource->files()->create([
            'user_owner_id' => $resource->user_owner_id,
            'filename' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'role' => $role,
            'relation' => $relation,
            'usage' => $usage,
            'disk' => $media->disk,
            'path' => $media->getPathRelativeToRoot(),
            'media_id' => $media->id,
            'is_active' => true,
        ]);
    }

    /**
     * Hashes of the resources this vault's `ingest` created and that still
     * exist — what a write key may `update`/`withdraw` here. Reported by the
     * write probe so a consumer can offer those actions on the right items.
     *
     * @return list<string>
     */
    public function ingested(Vault $vault): array
    {
        $hashes = VaultWrite::where('vault_id', $vault->id)
            ->where('method', 'ingest')
            ->pluck('summary')
            ->map(fn ($s) => is_array($s) ? ($s['hash'] ?? null) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($hashes === []) {
            return [];
        }

        return VaultLink::where('vault_id', $vault->id)
            ->whereNull('file_id')
            ->whereIn('hash', $hashes)
            ->whereHas('resource') // soft-deleted (withdrawn or trashed in TYDAL) drop out
            ->pluck('hash')
            ->values()
            ->all();
    }

    /**
     * Correct an ingested resource: merge the metadata document per key — a
     * value replaces, null removes; `name` can be changed but never removed.
     *
     * @return array{updated: string}
     */
    public function update(Vault $vault, string $hash, mixed $document): array
    {
        $resource = $this->ingestedResource($vault, $hash);
        ['columns' => $columns, 'metadata' => $metadata] = $this->document($document);

        if (array_key_exists('name', $columns) && $columns['name'] === null) {
            throw ValidationException::withMessages(['metadata' => 'name cannot be removed.']);
        }
        if ($columns === [] && $metadata === []) {
            throw ValidationException::withMessages(['metadata' => 'Nothing to update.']);
        }

        $merged = $resource->metadata ?? [];
        foreach ($metadata as $key => $value) {
            if ($value === null) {
                unset($merged[$key]);
            } else {
                $merged[$key] = $value;
            }
        }

        $this->resources->updateResource($resource->id, [
            ...$columns,
            'metadata' => $merged === [] ? null : $merged,
        ]);

        return ['updated' => $hash];
    }

    /**
     * Remove an ingested resource. It goes to the trash (soft delete),
     * recoverable by an org admin until pruned. Refused while a selection is
     * active: an opening's projection must not change underneath it.
     *
     * @return array{withdrawn: string}
     */
    public function withdraw(Vault $vault, string $hash): array
    {
        if ($vault->selection_snapshot !== null) {
            throw ValidationException::withMessages([
                'vault' => 'This vault has an active selection — close it before withdrawing.',
            ]);
        }

        $resource = $this->ingestedResource($vault, $hash);
        $this->resources->deleteResource($resource->id);

        return ['withdrawn' => $hash];
    }

    private function ingestedResource(Vault $vault, string $hash): Resource
    {
        $ingested = VaultWrite::where('vault_id', $vault->id)
            ->where('method', 'ingest')
            ->where('summary->hash', $hash)
            ->exists();

        $link = $ingested
            ? VaultLink::where('vault_id', $vault->id)->where('hash', $hash)->whereNull('file_id')->first()
            : null;
        $resource = $link ? Resource::find($link->resource_id) : null;

        if (! $resource) {
            throw ValidationException::withMessages([
                'resource' => 'Only resources ingested through this vault can be changed here.',
            ]);
        }

        return $resource;
    }
}
