<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `resources.archived_at`: when a resource was archived. `updated_at` moves
 * on any later write (an AITY status recompute, a tag change), so the
 * Archived page could not sort by "archived most recently". The Resource
 * model stamps this column when `state` becomes `archived` and clears it when
 * the resource leaves archived (Resource::syncArchivedAt()).
 *
 * Existing archived rows are backfilled with `updated_at`, the best
 * approximation there is (query builder on purpose: no model hooks, no
 * reindex). The column add is guarded and the backfill only fills nulls, so
 * re-running `up()` is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('resources', 'archived_at')) {
            Schema::table('resources', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable()->after('state');
                $table->index(['organization_id', 'state', 'archived_at'], 'resources_org_state_archived_at_index');
            });
        }

        DB::table('resources')
            ->where('state', 'archived')
            ->whereNull('archived_at')
            ->update(['archived_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('resources', 'archived_at')) {
            return;
        }

        Schema::table('resources', function (Blueprint $table) {
            $table->dropIndex('resources_org_state_archived_at_index');
            $table->dropColumn('archived_at');
        });
    }
};
