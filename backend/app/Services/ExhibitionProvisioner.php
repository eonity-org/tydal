<?php

namespace App\Services;

use App\Enums\OrganizationRole;
use App\Enums\VaultPurpose;
use App\Enums\VaultState;
use App\Jobs\RebuildVaultIndex;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\SearchIndex;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultKey;
use App\Models\Workspace;
use App\Services\Interfaces\CollectionServiceInterface;
use App\Services\Interfaces\VaultServiceInterface;
use Database\Seeders\PhotoSchemeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Prepares TYDAL to serve photo exhibitions to a gallery client such as Full
 * Frame — the `exhibitions:setup` / `exhibitions:create` commands.
 *
 * Setup (once per organization): the photo scheme, its index and the
 * organization's Photos collection. Create (once per exhibition): a workspace,
 * a private gallery vault showing it with that workspace + Photos as its
 * ingest target, and a read + write key — everything the client needs to
 * connect, upload, and publish, with nothing to configure by hand.
 */
class ExhibitionProvisioner
{
    /** Every write op a gallery client uses: the opening, and curator uploads. */
    public const WRITE_ABILITIES = ['w:activate', 'w:open', 'w:close', 'w:ingest', 'w:update', 'w:withdraw'];

    public const DEFAULT_INDEX = 'tydal_photo_exhibition';

    /** Roles a curator can be given here — ownership is never handed out by a command. */
    public const CURATOR_ROLES = ['viewer', 'editor', 'admin'];

    public function __construct(
        private CollectionServiceInterface $collections,
        private VaultServiceInterface $vaults,
        private ElasticsearchService $elasticsearch,
    ) {}

    /**
     * The photo scheme, an index and the organization's Photos collection.
     * Idempotent: an organization that already has a collection on the photo
     * scheme keeps it (and its index).
     *
     * @return array{collection: Collection, index: SearchIndex, created: bool}
     */
    public function setup(Organization $organization, ?string $indexName, string $collectionName): array
    {
        $scheme = PhotoSchemeSeeder::apply();

        $existing = $this->photoCollection($organization);
        if ($existing) {
            $index = $existing->searchIndex ?? throw new RuntimeException("Collection {$existing->name} has no search index.");
            // The scheme may have gained fields since — additive, never a rebuild.
            $this->elasticsearch->provisionIndex($index);

            return ['collection' => $existing, 'index' => $index, 'created' => false];
        }

        $index = $indexName !== null
            ? SearchIndex::where('index_name', $indexName)->first()
                ?? throw new RuntimeException("No search index named {$indexName}.")
            : SearchIndex::firstOrCreate(
                ['index_name' => self::DEFAULT_INDEX],
                [
                    'display_name' => 'Photo Exhibition Index',
                    'description' => 'Photographs with their curatorial details (author, technique, dimensions)',
                    'is_active' => true,
                ],
            );

        // createCollection provisions the index (creates it, or adds the
        // photo fields to a shared one) — never a recreate.
        $collection = $this->collections->createCollection([
            'organization_id' => $organization->id,
            'user_owner_id' => $this->owner($organization)->id,
            'name' => $collectionName,
            'description' => 'Exhibition photographs with their curatorial details (author, technique, dimensions)',
            'scheme_id' => $scheme->id,
            'index_id' => $index->id,
            'is_active' => true,
        ]);

        return ['collection' => $collection, 'index' => $index, 'created' => true];
    }

    /**
     * One exhibition: workspace, private gallery vault showing it (and landing
     * uploads in it), and its two keys. The plaintext keys are returned once.
     *
     * @return array{vault: Vault, workspace: Workspace, read_key: string, write_key: string}
     */
    public function create(Organization $organization, string $name, ?string $slug = null): array
    {
        $collection = $this->photoCollection($organization)
            ?? throw new RuntimeException("{$organization->name} has no photo collection yet — run exhibitions:setup first.");

        $slug = Str::slug($slug ?: $name);
        if ($slug === '') {
            throw new RuntimeException('The exhibition needs a name that makes a usable slug.');
        }
        if (Vault::where('organization_id', $organization->id)->where('slug', $slug)->exists()) {
            throw new RuntimeException("A vault with the slug {$slug} already exists in {$organization->name} — choose another --slug.");
        }

        return DB::transaction(function () use ($organization, $collection, $name, $slug): array {
            $owner = $this->owner($organization);

            $workspace = Workspace::firstOrCreate(
                ['organization_id' => $organization->id, 'slug' => $slug],
                [
                    'name' => $name,
                    'description' => "Photographs of the exhibition {$name}",
                    'user_owner_id' => $owner->id,
                    'is_default' => false,
                    'is_system' => false,
                    'is_active' => true,
                ],
            );

            $vault = $this->vaults->createVault([
                'organization_id' => $organization->id,
                'name' => $name,
                'slug' => $slug,
                'description' => "Exhibition {$name} — private until the opening",
                'purpose' => VaultPurpose::GALLERY,
                'state' => VaultState::PRIVATE->value,
                'has_public_workspace' => false,
                'is_downloadable' => false,
                'exposure_policy' => [
                    'ingest' => ['workspace_id' => $workspace->id, 'collection_id' => $collection->id],
                ],
            ]);
            $this->vaults->syncWorkspaces($vault, [$workspace->id]);
            RebuildVaultIndex::dispatch($vault->id);

            [, $read] = VaultKey::mint($vault, 'exhibition-read', ['read']);
            [, $write] = VaultKey::mint($vault, 'exhibition-write', self::WRITE_ABILITIES);

            return ['vault' => $vault, 'workspace' => $workspace, 'read_key' => $read, 'write_key' => $write];
        });
    }

    /**
     * Give someone access to the organization's exhibitions: create the TYDAL
     * user (with a generated password, returned once) or reuse an existing
     * one, and make them a member with at least `$role`. An existing higher
     * role is never lowered, and ownership is never granted here.
     *
     * @return array{user: User, password: string|null, role: string}
     */
    public function curator(Organization $organization, string $email, ?string $name, string $role): array
    {
        $order = self::CURATOR_ROLES; // lowest to highest
        if (! in_array($role, $order, true)) {
            throw new RuntimeException('The curator role must be viewer, editor or admin.');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("{$email} is not an email address.");
        }

        return DB::transaction(function () use ($organization, $email, $name, $role, $order): array {
            $password = null;
            $user = User::where('email', $email)->first();
            if (! $user) {
                $password = Str::password(16, symbols: false);
                $user = User::create([
                    'name' => $name ?: Str::before($email, '@'),
                    'email' => $email,
                    'password' => Hash::make($password),
                    'is_active' => true,
                    'is_superadmin' => false,
                    'last_organization_id' => $organization->id,
                ]);
            }

            $current = $organization->users()->where('users.id', $user->id)->first()?->getRelationValue('pivot')?->role;
            if ($current === null) {
                $organization->users()->attach($user->id, ['role' => $role]);
            } elseif ($current !== OrganizationRole::OWNER->value
                && array_search($current, $order, true) < array_search($role, $order, true)) {
                $organization->users()->updateExistingPivot($user->id, ['role' => $role]);
            } else {
                $role = $current; // already at least this — left as it is
            }

            return ['user' => $user, 'password' => $password, 'role' => $role];
        });
    }

    /** By slug or UUID. */
    public function organization(string $slugOrId): ?Organization
    {
        return Organization::where('slug', $slugOrId)->orWhere('id', $slugOrId)->first();
    }

    /** The organization's collection on the photo scheme, if it has one. */
    public function photoCollection(Organization $organization): ?Collection
    {
        return Collection::where('organization_id', $organization->id)
            ->whereHas('scheme', fn ($q) => $q->where('name', PhotoSchemeSeeder::NAME))
            ->oldest()
            ->first();
    }

    /** Who owns what these commands create: the organization's owner, else a platform admin. */
    private function owner(Organization $organization): User
    {
        return $organization->users()->wherePivot('role', OrganizationRole::OWNER->value)->first()
            ?? User::where('is_superadmin', true)->oldest()->first()
            ?? throw new RuntimeException("{$organization->name} has no owner and there is no platform admin to own the exhibition.");
    }
}
