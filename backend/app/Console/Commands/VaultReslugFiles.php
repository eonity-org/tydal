<?php

namespace App\Console\Commands;

use App\Models\Vault;
use App\Models\VaultLink;
use App\Services\VaultLinkService;
use Illuminate\Console\Command;

/**
 * Re-derive the human slugs of vault file links with the current rule.
 *
 * File slugs used to come from the uploaded filename, and a slug is stored
 * when its link is first minted — so links minted before the change still
 * publish filenames in their /v/ address (camera serials, dates, the names of
 * people photographed). This rewrites them with the current rule: a per-vault
 * code by default, the filename when TYDAL_EXPORT_VISIBLE_FILENAMES is on (see
 * VaultLinkService::generateLinkSlug). Run it after switching that setting
 * too. Hash addresses (/h/…) are untouched; old /v/ file paths stop resolving.
 *
 * Sibling of `vault:validate-policy`. See docs/architecture/VAULT_SYSTEM.md §4.3.
 */
class VaultReslugFiles extends Command
{
    protected $signature = 'vault:reslug-files
                            {--vault= : Only one vault (id, hash or slug)}
                            {--dry-run : Report the changes without writing them}';

    protected $description = 'Re-derive vault file-link slugs with the current rule (codes, or filenames when TYDAL_EXPORT_VISIBLE_FILENAMES is on)';

    public function handle(VaultLinkService $links): int
    {
        $query = Vault::query();

        if ($this->option('vault') !== null) {
            $needle = $this->option('vault');
            $query->where(fn ($q) => $q->where('id', $needle)
                ->orWhere('hash', $needle)
                ->orWhere('slug', $needle));
        }

        $vaults = $query->orderBy('slug')->get();

        if ($vaults->isEmpty()) {
            $this->warn('No vaults matched.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($vaults as $vault) {
            $resourceIds = VaultLink::where('vault_id', $vault->id)
                ->whereNull('workspace_id')
                ->whereNotNull('file_id')
                ->distinct()
                ->pluck('resource_id');

            $changes = [];
            foreach ($resourceIds as $resourceId) {
                foreach ($links->reslugFileLinks($vault, $resourceId, $dryRun) as $change) {
                    $changes[] = $change;
                }
            }

            if ($changes === []) {
                $this->info("✓ {$vault->slug} — nothing to change");

                continue;
            }

            $this->line(($dryRun ? '~ ' : '✎ ')."{$vault->slug} — ".count($changes).' file link(s)');
            foreach ($changes as $change) {
                $this->line('    '.($change['from'] ?? '(none)').' → '.$change['to']);
            }
            $total += count($changes);
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry run: {$total} file link(s) would change. Run again without --dry-run to apply."
            : "{$total} file link(s) re-slugged.");

        return self::SUCCESS;
    }
}
