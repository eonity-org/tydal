<?php

namespace App\Console\Commands;

use App\Enums\FileRole;
use App\Enums\ResourceState;
use App\Models\File;
use App\Models\Resource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audit (and optionally repair) resource ↔ file composition invariants —
 * Epic 1.1 backfill audit. The invariants (docs/RESOURCE_MODEL.md):
 *   1. at most one active canonical file per resource
 *   2. no active component beside an active canonical
 *   3. canonical files carry no relation
 *   4. at most one file flagged as snapshot
 *   5. (report-only) active resources with no active file
 *   6. (report-only) active files whose resource is soft-deleted
 *
 * --fix applies the documented demotion rules: newest canonical wins,
 * violating peers demote to supporting, relations are cleared, newest
 * snapshot wins.
 */
class ResourcesAuditRoles extends Command
{
    protected $signature = 'resources:audit-roles {--fix : Repair violations using the demotion rules}';

    protected $description = 'Audit resource file-role composition invariants (single canonical, component exclusivity, snapshot uniqueness)';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $violations = 0;

        // 1. Multiple active canonicals — newest wins, the rest demote
        $multiCanonical = File::query()
            ->select('resource_id')
            ->where('role', FileRole::CANONICAL->value)
            ->where('is_active', true)
            ->groupBy('resource_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('resource_id');

        foreach ($multiCanonical as $resourceId) {
            $violations++;
            $this->warn("resource {$resourceId}: multiple active canonical files");

            if ($fix) {
                $keep = File::where('resource_id', $resourceId)
                    ->where('role', FileRole::CANONICAL->value)
                    ->where('is_active', true)
                    ->orderByDesc('created_at')
                    ->value('id');

                File::where('resource_id', $resourceId)
                    ->where('role', FileRole::CANONICAL->value)
                    ->where('id', '!=', $keep)
                    ->update(['role' => FileRole::SUPPORTING->value]);
                $this->line('  fixed: kept newest canonical, demoted the rest to supporting');
            }
        }

        // 2. Components beside an active canonical — demote to supporting
        $componentBesideCanonical = File::query()
            ->where('role', FileRole::COMPONENT->value)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('files as canon')
                    ->whereColumn('canon.resource_id', 'files.resource_id')
                    ->where('canon.role', FileRole::CANONICAL->value)
                    ->where('canon.is_active', true);
            })
            ->pluck('id', 'resource_id');

        foreach ($componentBesideCanonical as $resourceId => $fileId) {
            $violations++;
            $this->warn("resource {$resourceId}: component file beside an active canonical");

            if ($fix) {
                File::whereKey($fileId)->update(['role' => FileRole::SUPPORTING->value]);
                $this->line('  fixed: demoted component to supporting');
            }
        }

        // 3. Canonical files carrying a relation
        $canonicalWithRelation = File::query()
            ->where('role', FileRole::CANONICAL->value)
            ->whereNotNull('relation')
            ->pluck('id');

        foreach ($canonicalWithRelation as $fileId) {
            $violations++;
            $this->warn("file {$fileId}: canonical file carries a relation");

            if ($fix) {
                File::whereKey($fileId)->update(['relation' => null]);
                $this->line('  fixed: cleared relation');
            }
        }

        // 4. Multiple snapshot flags — newest wins
        $multiSnapshot = File::query()
            ->select('resource_id')
            ->whereJsonContains('usage', 'snapshot')
            ->groupBy('resource_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('resource_id');

        foreach ($multiSnapshot as $resourceId) {
            $violations++;
            $this->warn("resource {$resourceId}: multiple snapshot files");

            if ($fix) {
                $files = File::where('resource_id', $resourceId)
                    ->whereJsonContains('usage', 'snapshot')
                    ->orderByDesc('created_at')
                    ->get();

                foreach ($files->slice(1) as $file) {
                    $usage = array_values(array_filter($file->usage ?? [], fn ($u) => $u !== 'snapshot'));
                    File::whereKey($file->id)->update(['usage' => $usage === [] ? null : json_encode($usage)]);
                }
                $this->line('  fixed: kept newest snapshot, cleared the rest');
            }
        }

        // 5. Report-only: active resources with no active file
        $fileless = Resource::where('state', ResourceState::LIVE->value)
            ->whereDoesntHave('files', fn ($q) => $q->where('is_active', true))
            ->count();

        if ($fileless > 0) {
            $this->line("info: {$fileless} active resource(s) have no active file (allowed — metadata-only resources)");
        }

        // 6. Report-only: active files on soft-deleted resources
        $orphanFiles = File::where('is_active', true)
            ->whereIn('resource_id', Resource::onlyTrashed()->select('id'))
            ->count();

        if ($orphanFiles > 0) {
            $violations++;
            $this->warn("{$orphanFiles} active file(s) belong to soft-deleted resources (restore or force-delete the resources)");
        }

        if ($violations === 0) {
            $this->info('All composition invariants hold.');

            return self::SUCCESS;
        }

        if ($fix) {
            $this->info("Audit complete — repairs applied where possible ({$violations} finding(s)).");

            return self::SUCCESS;
        }

        $this->warn("{$violations} violation(s) found. Run with --fix to repair.");

        return self::FAILURE;
    }
}
